<?php

class ImageFapScraper {
    private $baseUrl = 'https://www.imagefap.com';
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';
    private $cookie = null;
    private $minTimePage = 2000;
    private $maxRetries = 3;
    private $singlePage = false;
    private $skipImageDetails = false;

    public function __construct($options = []) {
        if (isset($options['minTimePage'])) {
            $this->minTimePage = $options['minTimePage'];
        }
        if (isset($options['maxRetries'])) {
            $this->maxRetries = $options['maxRetries'];
        }
        if (isset($options['singlePage'])) {
            $this->singlePage = $options['singlePage'];
        }
        if (isset($options['skipImageDetails'])) {
            $this->skipImageDetails = $options['skipImageDetails'];
        }
        $this->initCookie();
    }

    private function initCookie() {
        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $response = curl_exec($ch);

        if (preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches)) {
            $this->cookie = implode('; ', $matches[1]);
        }

        curl_close($ch);
    }

    private function fetchPage($url, $retries = 0, $headers = []) {
        usleep($this->minTimePage * 1000);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($this->cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }

        if (!empty($headers)) {
            $headerArray = [];
            foreach ($headers as $key => $value) {
                $headerArray[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        curl_close($ch);

        if ($html === false || $httpCode !== 200) {
            if ($retries < $this->maxRetries) {
                sleep(2);
                return $this->fetchPage($url, $retries + 1);
            }
            throw new Exception("Failed to fetch page: $url (HTTP $httpCode)");
        }

        if (strpos($finalUrl, '/human-verification') !== false) {
            throw new Exception("Too many requests. Please increase minTimePage value.");
        }

        return ['html' => $html, 'url' => $finalUrl];
    }

    private function htmlToText($html) {
        if (empty($html)) {
            return null;
        }
        $text = strip_tags($html);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function parseGalleryFolderLinks($html, $linkPathname) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $folders = [];

        $input = $xpath->query('//input[@id="tgl_all"]')->item(0);
        if (!$input) {
            return $folders;
        }

        $folderIds = explode('|', $input->getAttribute('value'));

        foreach ($folderIds as $id) {
            if (empty($id)) continue;

            $linkNodes = $xpath->query("//a[contains(@href, 'https://www.imagefap.com/$linkPathname') and contains(@href, 'folderid=$id')]");

            if ($linkNodes->length > 0) {
                $linkNode = $linkNodes->item(0);
                $href = $linkNode->getAttribute('href');
                $innerHTML = $dom->saveHTML($linkNode);

                $selected = preg_match('/<b>(.+?)<\/b>/', $innerHTML, $matches);
                $title = $this->htmlToText($innerHTML);

                if ($href && $title) {
                    $folders[] = [
                        'url' => $href,
                        'id' => intval($id),
                        'title' => $title,
                        'selected' => (bool)$selected
                    ];
                }
            }
        }

        return $folders;
    }

    public function parseUserGalleriesPage($html) {
        return $this->parseGalleryFolderLinks($html, 'usergallery.php');
    }

    public function parseFavoritesPage($html) {
        return $this->parseGalleryFolderLinks($html, 'showfavorites.php');
    }

    public function parseGalleryLinks($html, $baseUrl) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $links = [];

        $rows = $xpath->query('//table//tr[starts-with(@id, "gid-")]');

        foreach ($rows as $row) {
            $gidAttr = $row->getAttribute('id');
            $gid = intval(substr($gidAttr, 4));

            if ($gid > 0) {
                $linkNodes = $xpath->query(".//a[contains(@href, '/gallery/$gid')]", $row);

                if ($linkNodes->length > 0) {
                    $linkNode = $linkNodes->item(0);
                    $href = $linkNode->getAttribute('href');
                    $title = $this->htmlToText($linkNode->nodeValue);

                    if (preg_match('/\/gallery\/(\d+)/', $href, $matches)) {
                        $realGid = intval($matches[1]);

                        if ($href && $title && $realGid > 0) {
                            $fullUrl = (strpos($href, 'http') === 0) ? $href : $this->baseUrl . $href;

                            $links[] = [
                                'id' => $realGid,
                                'url' => $fullUrl,
                                'title' => $title
                            ];
                        }
                    }
                }
            }
        }

        $nextUrl = null;
        $nextLinks = $xpath->query('//a[text()=":: next ::"]');
        if ($nextLinks->length > 0) {
            $href = $nextLinks->item(0)->getAttribute('href');
            if ($href) {
                $nextUrl = (strpos($href, 'http') === 0) ? $href : $this->baseUrl . $href;
            }
        }

        return ['galleryLinks' => $links, 'nextUrl' => $nextUrl];
    }

    public function parseGalleryPage($html, $baseUrl) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $titleNode = $xpath->query('//head/title')->item(0);
        $title = $titleNode ? $this->htmlToText($titleNode->nodeValue) : 'Untitled';

        $galleryIdNode = $xpath->query('//input[@id="galleryid_input"]')->item(0);
        $galleryId = $galleryIdNode ? intval($galleryIdNode->getAttribute('value')) : null;

        $descNode = $xpath->query('//span[@id="cnt_description"]')->item(0);
        $description = $descNode ? $this->htmlToText($dom->saveHTML($descNode)) : null;

        $imageLinks = $this->parseImageLinks($html, $baseUrl);

        return [
            'id' => $galleryId,
            'title' => $title,
            'description' => $description,
            'imageLinks' => $imageLinks['imageLinks'],
            'nextUrl' => $imageLinks['nextUrl']
        ];
    }

    public function parseImageLinks($html, $baseUrl) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $links = [];

        $linkNodes = $xpath->query('//a[starts-with(@href, "/photo/")]');

        foreach ($linkNodes as $linkNode) {
            $href = $linkNode->getAttribute('href');
            $name = $linkNode->getAttribute('name');

            if (preg_match('/\/photo\/(\d+)\//', $href, $matches)) {
                $imageId = $matches[1];

                if ($name === $imageId) {
                    $row = $linkNode->parentNode;
                    while ($row && $row->nodeName !== 'tr') {
                        $row = $row->parentNode;
                    }

                    if ($row && $row->nextSibling) {
                        $nextRow = $row->nextSibling;
                        while ($nextRow && $nextRow->nodeType !== XML_ELEMENT_NODE) {
                            $nextRow = $nextRow->nextSibling;
                        }

                        $title = null;
                        $src = null;
                        if ($nextRow) {
                            $fonts = $xpath->query('.//font', $nextRow);
                            if ($fonts->length > 1) {
                                $title = $this->htmlToText($fonts->item(1)->nodeValue);
                            }
                        }

                        $imgNodes = $xpath->query('.//img', $linkNode);
                        if ($imgNodes->length > 0) {
                            $imgSrc = $imgNodes->item(0)->getAttribute('src');
                            if ($imgSrc && strpos($imgSrc, 'cdn') !== false) {
                                $src = $imgSrc;
                            }
                        }

                        $fullUrl = (strpos($href, 'http') === 0) ? $href : $this->baseUrl . $href;

                        $links[] = [
                            'id' => intval($imageId),
                            'url' => $fullUrl,
                            'title' => $title,
                            'src' => $src
                        ];
                    }
                }
            }
        }

        $nextUrl = null;
        $nextLinks = $xpath->query('//a[text()=":: next ::"]');
        if ($nextLinks->length > 0) {
            $href = $nextLinks->item(0)->getAttribute('href');
            if ($href) {
                $nextUrl = (strpos($href, 'http') === 0) ? $href : $this->baseUrl . $href;
            }
        }

        return ['imageLinks' => $links, 'nextUrl' => $nextUrl];
    }

    public function parseImageNav($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $isEmpty = $xpath->query('//input[@id="is_empty"][@value="1"]');
        if ($isEmpty->length > 0) {
            return [];
        }

        $images = [];
        $thumbs = $xpath->query('//div[@id="_navi_cavi"]//ul[@class="thumbs"]//li//a');

        foreach ($thumbs as $thumb) {
            $imageId = $thumb->getAttribute('imageid');
            $src = $thumb->getAttribute('original');
            $views = $thumb->getAttribute('views');
            $dateAdded = $thumb->getAttribute('added');
            $dimension = $thumb->getAttribute('dimension');
            $votes = $thumb->getAttribute('votes');

            $rating = null;
            if ($votes) {
                $voteParts = explode('|', $votes);
                if (count($voteParts) > 0) {
                    $rating = intval($voteParts[0]);
                }
            }

            if ($imageId && $src) {
                $images[] = [
                    'id' => intval($imageId),
                    'src' => $src,
                    'views' => $views ? intval($views) : null,
                    'dimension' => $dimension,
                    'dateAdded' => $dateAdded,
                    'rating' => $rating
                ];
            }
        }

        return $images;
    }

    public function getGalleryFolder($url) {
        $result = $this->fetchPage($url);
        $html = $result['html'];
        $lastUrl = $result['url'];

        $parsed = $this->parseGalleryLinks($html, $lastUrl);

        $folders = $this->parseUserGalleriesPage($html);
        $selectedFolder = null;
        foreach ($folders as $folder) {
            if ($folder['selected']) {
                $selectedFolder = $folder;
                break;
            }
        }

        $galleryLinks = $parsed['galleryLinks'];

        if (!$this->singlePage) {
            while ($parsed['nextUrl']) {
                usleep($this->minTimePage * 1000);
                $result = $this->fetchPage($parsed['nextUrl']);
                $parsed = $this->parseGalleryLinks($result['html'], $result['url']);
                $galleryLinks = array_merge($galleryLinks, $parsed['galleryLinks']);
            }
        }

        return [
            'folder' => $selectedFolder,
            'galleryLinks' => $galleryLinks
        ];
    }

    public function getGallery($url) {
        $result = $this->fetchPage($url);
        $html = $result['html'];
        $lastUrl = $result['url'];

        $parsed = $this->parseGalleryPage($html, $lastUrl);

        $imageLinks = $parsed['imageLinks'];

        if (!$this->singlePage) {
            while ($parsed['nextUrl']) {
                usleep($this->minTimePage * 1000);
                $result = $this->fetchPage($parsed['nextUrl']);
                $nextParsed = $this->parseGalleryPage($result['html'], $result['url']);
                $imageLinks = array_merge($imageLinks, $nextParsed['imageLinks']);
                $parsed['nextUrl'] = $nextParsed['nextUrl'];
            }
        }

        $images = [];

        if ($this->skipImageDetails) {
            $images = $imageLinks;
        } else if (count($imageLinks) > 0) {
            $galleryId = $parsed['id'];
            $referrerImageId = $imageLinks[0]['id'];
            $navIdx = 0;

            while ($navIdx < count($imageLinks)) {
                $imageNavUrl = $this->constructImageNavURL($referrerImageId, $galleryId, $navIdx);
                $refererUrl = $this->constructImageNavRefererURL($referrerImageId, $galleryId);

                $headers = [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => $refererUrl
                ];

                usleep($this->minTimePage * 1000);
                $result = $this->fetchPage($imageNavUrl, 0, $headers);
                $navImages = $this->parseImageNav($result['html']);

                $images = array_merge($images, $navImages);

                if (count($navImages) > 0) {
                    $lastImage = end($navImages);
                    $referrerImageId = $lastImage['id'];
                    $navIdx += count($navImages);
                } else {
                    break;
                }

                if ($navIdx >= count($imageLinks)) {
                    break;
                }
            }
        }

        if (!$this->skipImageDetails) {
            foreach ($images as &$image) {
                foreach ($imageLinks as $link) {
                    if ($link['id'] === $image['id'] && $link['title']) {
                        $image['title'] = $link['title'];
                        break;
                    }
                }
            }
        }

        return [
            'id' => $parsed['id'],
            'title' => $parsed['title'],
            'description' => $parsed['description'],
            'images' => $images
        ];
    }

    private function constructImageNavURL($referrerImageId, $galleryId, $startIndex) {
        return "{$this->baseUrl}/photo/{$referrerImageId}/?gid={$galleryId}&idx={$startIndex}&partial=true";
    }

    private function constructImageNavRefererURL($referrerImageId, $galleryId) {
        return "{$this->baseUrl}/photo/{$referrerImageId}/?pgid=&gid={$galleryId}&page=0";
    }

    public function getUserGalleries($url) {
        $result = $this->fetchPage($url);
        $folders = $this->parseUserGalleriesPage($result['html']);

        return [
            'folders' => $folders
        ];
    }

    public function scrapeTarget($url) {
        $targetType = $this->getTargetType($url);

        switch ($targetType) {
            case 'userGalleries':
                return $this->getUserGalleries($url);

            case 'galleryFolder':
                return $this->getGalleryFolder($url);

            case 'gallery':
                return $this->getGallery($url);

            default:
                throw new Exception("Unsupported target type: $targetType");
        }
    }

    private function getTargetType($url) {
        if (preg_match('/\/profile\/[^\/]+\/galleries\??(?!.*folderid=)/', $url)) {
            return 'userGalleries';
        }

        if (preg_match('/\/profile\/[^\/]+\/galleries\?.*folderid=/', $url) ||
            preg_match('/\/organizer\//', $url) ||
            preg_match('/\/usergallery\.php\?.*folderid=/', $url)) {
            return 'galleryFolder';
        }

        if (preg_match('/\/gallery\/\d+/', $url) ||
            preg_match('/\/gallery\.php\?gid=/', $url) ||
            preg_match('/\/pictures\/\d+/', $url)) {
            return 'gallery';
        }

        if (preg_match('/\/showfavorites\.php\?userid=/', $url)) {
            if (preg_match('/folderid=/', $url)) {
                return 'favoritesFolder';
            }
            return 'favorites';
        }

        throw new Exception("Invalid or unsupported URL format");
    }
}
