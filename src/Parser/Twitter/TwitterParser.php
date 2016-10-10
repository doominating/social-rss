<?php
namespace SocialRss\Parser\Twitter;

use SocialRss\Exception\SocialRssException;
use \TwitterAPIExchange;
use SocialRss\Parser\ParserInterface;
use SocialRss\Parser\ParserTrait;

/**
 * Class TwitterParser
 * @package SocialRss\Parser\Twitter
 */
class TwitterParser implements ParserInterface
{
    use ParserTrait;

    const NAME = 'Twitter';
    const URL = 'https://twitter.com/';

    const API_URL_HOME = 'https://api.twitter.com/1.1/statuses/home_timeline.json';
    const API_URL_USER = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
    const API_PARAMETERS = '?count=100&tweet_mode=extended';

    private $twitterClient;

    /**
     * TwitterParser constructor.
     * @param $config
     */
    public function __construct($config)
    {
        $this->twitterClient = new TwitterAPIExchange($config);
    }

    /**
     * @param $username
     * @return mixed
     * @throws SocialRssException
     */
    public function getFeed($username)
    {
        if (empty($username)) {
            $url = self::API_URL_HOME;
            $parameters = self::API_PARAMETERS;
        } else {
            $url = self::API_URL_USER;
            $parameters = self::API_PARAMETERS . "&screen_name={$username}";
        }

        $twitterJson = $this->twitterClient
            ->setGetfield($parameters)
            ->buildOauth($url, 'GET')
            ->performRequest();

        $feed = json_decode($twitterJson, true);

        if (isset($feed['errors'])) {
            throw new SocialRssException($feed['errors'][0]['message']);
        }

        return $feed;
    }

    /**
     * @param $feed
     * @return array
     */
    public function parseFeed($feed)
    {
        // Parse items
        $items = array_map(function ($item) {
            return $this->parseItem($item);
        }, $feed);

        $filtered = array_filter($items, function ($item) {
            return !empty($item);
        });

        return [
            'title' => self::NAME,
            'link' => self::URL,
            'items' => $filtered,
        ];
    }

    /**
     * @param $item
     * @return array
     */
    protected function parseItem($item)
    {
        $tweet = $item;
        $titlePart = '';

        if (isset($item['retweeted_status'])) {
            $tweet = $item['retweeted_status'];
            $titlePart = " (RT by {$item['user']['name']})";
        }

        $quote = isset($tweet['quoted_status']) ? [
            'title' => $tweet['quoted_status']['user']['name'],
            'link' => self::URL .
                "{$tweet['quoted_status']['user']['screen_name']}/status/{$tweet['quoted_status']['id_str']}",
            'content' => $this->parseContent($tweet['quoted_status']),
        ] : [];

        return [
            'title' => $tweet['user']['name'] . $titlePart,
            'link' => self::URL . "{$tweet['user']['screen_name']}/status/{$tweet['id_str']}",
            'content' => $this->parseContent($tweet),
            'date' => strtotime($item['created_at']),
            'tags' => $this->parseTags($tweet),
            'author' => [
                'name' => $tweet['user']['name'],
                'avatar' => $tweet['user']['profile_image_url_https'],
                'link' => self::URL . $tweet['user']['screen_name'],
            ],
            'quote' => $quote,
        ];
    }

    /**
     * @param $tweet
     * @return array
     */
    private function parseContent($tweet)
    {
        $entitiesMap = [
            'hashtags' => function ($text, $item) {
                return $this->replaceContent(
                    $text,
                    "#{$item['text']}",
                    $this->makeLink(self::URL . "hashtag/{$item['text']}", "#{$item['text']}")
                );
            },
            'user_mentions' => function ($text, $item) {
                return $this->replaceContent(
                    $text,
                    "@{$item['screen_name']}",
                    $this->makeLink(self::URL . $item['screen_name'], "@{$item['screen_name']}")
                );
            },
            'urls' => function ($text, $item) {
                return $this->replaceContent(
                    $text,
                    $item['url'],
                    $this->makeLink($item['expanded_url'], $item['display_url'])
                );
            },
            'symbols' => function ($text, $item) {
                return $this->replaceContent(
                    $text,
                    '$' . $item['text'],
                    $this->makeLink(self::URL . "search?q=%24{$item['text']}", '$' . $item['text'])
                );
            },
            'media' => function ($text, $item) {
                switch ($item['type']) {
                    case 'photo':
                        return $this->replaceContent(
                            $text,
                            $item['url'],
                            ''
                        ) .
                        PHP_EOL . $this->makeImg($item['media_url_https'], $item['expanded_url']);

                    case 'video':
                    case 'animated_gif':
                        $videoVariants = array_reduce($item['video_info']['variants'], function ($acc, $variant) {
                            if ($variant['content_type'] === 'video/mp4') {
                                $acc[] = $variant;
                            }
                            return $acc;
                        }, []);

                        if (empty($videoVariants)) {
                            $media = $this->makeImg($item['media_url_https']);
                        } else {
                            $media = $this->makeVideo($videoVariants[0]['url'], $item['media_url_https']);
                        }

                        return $this->replaceContent(
                            $text,
                            $item['url'],
                            ''
                        ) .
                        PHP_EOL . $media;
                }

                return $text . PHP_EOL . "[Tweet contains unknown media type {$item['type']}]";
            },
        ];

        $text = $tweet['full_text'];

        if (!isset($tweet['extended_entities'])) {
            $tweet['extended_entities'] = [];
        }
        $tweetEntities = array_merge($tweet['entities'], $tweet['extended_entities']);

        $processedEntities = array_map(function ($type, $entitiesArray) {
            return array_map(function ($entity) use ($type) {
                $entity['entity_type'] = $type;
                return $entity;
            }, $entitiesArray);
        }, array_keys($tweetEntities), $tweetEntities);

        $flatEntities = array_reduce($processedEntities, function ($acc, $entitiesArray) {
            return !empty($entitiesArray) ? array_merge($acc, $entitiesArray) : $acc;
        }, []);

        $processedText = array_reduce($flatEntities, function ($acc, $entity) use ($entitiesMap) {
            $type = $entity['entity_type'];
            if (isset($entitiesMap[$type])) {
                return $entitiesMap[$type]($acc, $entity);
            }
            return $acc . PHP_EOL . "[Tweet contains unknown entity type {$entity['type']}]";
        }, $text);

        return nl2br(trim($processedText));
    }

    /**
     * @param $tweet
     * @return array
     */
    private function parseTags($tweet)
    {
        if (!isset($tweet['entities']['hashtags'])) {
            return [];
        }

        return array_map(function ($hashtag) {
            return $hashtag['text'];
        }, $tweet['entities']['hashtags']);
    }

    /**
     * @param $text
     * @param $search
     * @param $replace
     * @return mixed
     */
    private function replaceContent($text, $search, $replace)
    {
        $quotedSearch = preg_quote($search, '/');
        return preg_replace("/({$quotedSearch})(\s|$)/i", $replace . ' ', $text, 1);
    }
}