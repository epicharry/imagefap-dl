# ImageFap PHP Scraper

A PHP version of the ImageFap gallery scraper that returns JSON data.

## Requirements

- PHP 7.4 or higher
- cURL extension enabled
- DOM extension enabled

## Files

- `ImageFapScraper.php` - Main scraper class
- `scrape.php` - Web API endpoint that returns JSON
- `example.php` - Command-line usage example

## Usage

### Command Line

```bash
php example.php "https://www.imagefap.com/gallery/1234567"
```

### Web API

Place files on a PHP-enabled web server and access via HTTP:

```
GET /scrape.php?url=https://www.imagefap.com/gallery/1234567
```

Optional parameters:
- `minTimePage` - Minimum milliseconds between page requests (default: 2000)
- `maxRetries` - Maximum retry attempts (default: 3)

Example:
```
GET /scrape.php?url=https://www.imagefap.com/gallery/1234567&minTimePage=3000
```

### PHP Code

```php
require_once 'ImageFapScraper.php';

$scraper = new ImageFapScraper([
    'minTimePage' => 2000,
    'maxRetries' => 3
]);

// Scrape a gallery
$result = $scraper->scrapeTarget('https://www.imagefap.com/gallery/1234567');

// Output JSON
echo json_encode($result, JSON_PRETTY_PRINT);
```

## Supported URL Formats

### User Galleries
```
https://www.imagefap.com/profile/<username>/galleries
```
Returns: List of gallery folders

### Gallery Folder
```
https://www.imagefap.com/profile/<username>/galleries?folderid=<folder-id>
https://www.imagefap.com/organizer/<folder-id>/<folder-slug>
https://www.imagefap.com/usergallery.php?userid=<user-id>&folderid=<folder-id>
```
Returns: List of galleries in folder

### Single Gallery
```
https://www.imagefap.com/gallery/<gallery-id>
https://www.imagefap.com/gallery.php?gid=<gallery-id>
https://www.imagefap.com/pictures/<gallery-id>/<gallery-slug>
```
Returns: Gallery details with image list

## Response Format

### User Galleries Response
```json
{
  "folders": [
    {
      "url": "https://www.imagefap.com/...",
      "id": 12345,
      "title": "Folder Name",
      "selected": false
    }
  ]
}
```

### Gallery Folder Response
```json
{
  "folder": {
    "url": "https://www.imagefap.com/...",
    "id": 12345,
    "title": "Folder Name",
    "selected": true
  },
  "galleryLinks": [
    {
      "id": 67890,
      "url": "https://www.imagefap.com/gallery/67890",
      "title": "Gallery Title"
    }
  ]
}
```

### Gallery Response
```json
{
  "id": 67890,
  "title": "Gallery Title",
  "description": "Gallery description",
  "images": [
    {
      "id": 111111,
      "src": "https://cdn.imagefap.com/images/full/...",
      "title": "Image Title",
      "views": 1234,
      "dimension": "1920x1080",
      "dateAdded": "2024-01-01",
      "rating": 5
    }
  ]
}
```

## Methods

### Main Methods

- `scrapeTarget($url)` - Auto-detect URL type and scrape
- `getUserGalleries($url)` - Get all gallery folders for a user
- `getGalleryFolder($url)` - Get all galleries in a folder
- `getGallery($url)` - Get gallery details with images

### Parser Methods

- `parseUserGalleriesPage($html)` - Parse user galleries page
- `parseGalleryLinks($html, $baseUrl)` - Parse gallery links from folder
- `parseGalleryPage($html, $baseUrl)` - Parse gallery page
- `parseImageLinks($html, $baseUrl)` - Parse image links from gallery
- `parseImageNav($html)` - Parse image navigation JSON

## Error Handling

All methods throw exceptions on errors:

```php
try {
    $result = $scraper->scrapeTarget($url);
    echo json_encode($result);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

## Rate Limiting

The scraper includes built-in rate limiting:
- Default: 2000ms between page requests
- Configurable via `minTimePage` option
- Automatic retry on failures (max 3 attempts)

**Important:** Do not set `minTimePage` below 2000ms to avoid "Too many requests" errors.

## Notes

- This scraper is designed for personal use only
- Respect website terms of service
- Use appropriate rate limiting
- Only scrape content you have permission to access
