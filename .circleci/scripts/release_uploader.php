<?php

require_once __DIR__ . '/lib/HttpClient.php';
require_once dirname(dirname(__DIR__)) . '/src/includes/modules/payment/coinbase/const.php';


class AssetUploader
{
    const GITHUB_API_REPOS = 'https://api.github.com/repos';
    const USER = 'chalk777';
    const REPO_NAME = 'coinbase-commerce-oscommerce-build';
    const PLUGIN_VERSION = PLUGIN_VERSION;

    public function __construct($file, $token)
    {
        $this->headers = [
            sprintf('Authorization: token %s', $token),
            'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)'
        ];

        $this->file = $file;
        $this->client = HttpClient::getInstance();
    }

    public function run()
    {
        $release = $this->createRelease();
        $this->uploadAssets($release);
    }

    function createRelease() {
        $apiUrl = self::GITHUB_API_REPOS . DIRECTORY_SEPARATOR . self::USER . DIRECTORY_SEPARATOR . self::REPO_NAME . DIRECTORY_SEPARATOR . 'releases';

        $response = $this->client->request('GET', $apiUrl, [], '', $this->headers);
        // Check is release with current plugin version == tag name exists
        foreach ($response->bodyArray as $release) {
            if ($release['tag_name'] === self::PLUGIN_VERSION) {
                return $release;
            }
        }

        // Create release
        $body = [
            'tag_name' => self::PLUGIN_VERSION,
            'name' => ''
        ];

        $response = $this->client->request('POST', $apiUrl, [], json_encode($body), $this->headers);

        return $response->bodyArray;
    }

    function uploadAssets($release) {

        $path_parts = pathinfo($this->file);
        $fileLabel = self::REPO_NAME . '_' . str_replace('.', '_', self::PLUGIN_VERSION) . '.' . $path_parts['extension'];
        $fileName = self::REPO_NAME . '_' . str_replace('.', '_', self::PLUGIN_VERSION) . '_' . time()  . '.' . $path_parts['extension'];

        // Check is previous file was uploaded
        if (isset($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if ($asset['label'] == $fileLabel) {
                    // Delete asset
                    $this->client->request('DELETE', $asset['url'], [], '', $this->headers);
                }
            }
        }

        $headers = array_merge($this->headers, ['Content-Type: multipart/form-data']);
        $response = $this->client->request(
            'POST',
            str_replace('{?name,label}', '', $release['upload_url']),
            ['name' => $fileName, 'label' => $fileLabel],
            file_get_contents($this->file),
            $headers
        );


        if ($response->code == '201') {
            echo sprintf('Successfully uploaded file. File path: %s', $response->bodyArray["url"]) . PHP_EOL;
        }
    }
}

$longopts  = array(
    "token:",
    "file:"
);

$options = getopt('', $longopts);

$file = $options['file'];
$token = $options['token'];

$handler = new AssetUploader($file, $token);
$handler->run();



//$token = 'bb147e3849fc7efede101709b54aa6983ee994c4';



