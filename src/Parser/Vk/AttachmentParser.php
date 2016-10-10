<?php


namespace SocialRss\Parser\Vk;

use SocialRss\Parser\ParserTrait;

/**
 * Class AttachmentParser
 * @package SocialRss\Parser\Vk
 */
class AttachmentParser
{
    use ParserTrait;
    use VkParserTrait;
    const URL = 'https://vk.com/';

    private $item;

    private $attachmentsMap = [
        'photo' => 'makePhoto',
        'posted_photo' => 'makePostedPhoto',
        'video' => 'makeVideoAttachment',
        'audio' => 'makeAudio',
        'doc' => 'makeDoc',
        'graffiti' => 'makeGraffiti',
        'link' => 'makeLinkAttach',
        'note' => 'makeNote',
        'app' => 'makeApp',
        'poll' => 'makePoll',
        'page' => 'makePage',
        'album' => 'makeAlbum',
        'photos_list' => 'makePhotosList',
    ];

    /**
     * AttachmentParser constructor.
     * @param $item
     */
    public function __construct($item)
    {
        $this->item = $item;
    }

    /**
     * @return string
     */
    public function parseAttachments()
    {
        if (!isset($this->item['attachments'])) {
            return '';
        }

        $map = $this->attachmentsMap;

        $attachments = array_map(function ($attachment) use ($map) {
            $type = $attachment['type'];

            if (!isset($map[$type])) {
                return "[Item contains unknown attachment type {$attachment['type']}]";
            }

            $method = $map[$type];
            return $this->$method($attachment);
        }, $this->item['attachments']);

        return implode(PHP_EOL, $attachments);
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makePhoto($attachment)
    {
        return $this->makeImg($attachment['photo']['src_big']);
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makePostedPhoto($attachment)
    {
        return $this->makeImg($attachment['posted_photo']['photo_604']);
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makeVideoAttachment($attachment)
    {
        return $this->makeVideoTrait($attachment['video']);
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makeAudio($attachment)
    {
        return "Аудиозапись: " .
        "{$attachment['audio']['artist']} &ndash; {$attachment['audio']['title']}";
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makeDoc($attachment)
    {
        return 'Документ: ' .
        $this->makeLink($attachment['doc']['url'], $attachment['doc']['title']);
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makeGraffiti($attachment)
    {
        return 'Граффити: ' . $this->makeImg($attachment['graffiti']['photo_604']);
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makeLinkAttach($attachment)
    {
        $linkUrl = $attachment['link']['url'];
        $linkTitle = $attachment['link']['title'];

        $link = $this->makeLink($linkUrl, $linkTitle);

        $description = $attachment['link']['description'];

        if (isset($attachment['link']['image_src'])) {
            $preview = $attachment['link']['image_src'];
            $url = $attachment['link']['url'];

            $description = $this->makeImg($preview, $url) . PHP_EOL . $description;
        }

        return PHP_EOL . 'Ссылка: ' . $link . PHP_EOL . $description;
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makeNote($attachment)
    {
        $noteLink = $attachment['note']['view_url'];
        $noteTitle = $attachment['note']['title'];

        return 'Заметка: ' . $this->makeLink($noteLink, $noteTitle);
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makeApp($attachment)
    {
        return "Приложение: {$attachment['app']['name']}";
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makePoll($attachment)
    {
//        $answers = array_map(function ($answer) {
//            return $answer['text'];
//        }, $attachment['poll']['answers']);

        return "Опрос: {$attachment['poll']['question']}";
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makePage($attachment)
    {
        $pageLink = $attachment['page']['view_url'];
        $pageTitle = $attachment['page']['title'];

        return 'Страница: ' . $this->makeLink($pageLink, $pageTitle);
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makeAlbum($attachment)
    {
        $albumTitle = $attachment['album']['title'];
        $albumSize = $attachment['album']['size'];

        return "Альбом: $albumTitle ($albumSize фото)";
    }

    /**
     * @param $attachment
     * @return string
     */
    private function makePhotosList($attachment)
    {
        return '[Список фотографий]';
    }
}