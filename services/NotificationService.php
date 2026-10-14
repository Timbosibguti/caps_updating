<?php

namespace Services;

use Config\Config;

class NotificationService {
    private $slackWebhookUrl;
    private $slackChannel;
    private $slackUsername;

    public function __construct($slackWebhookUrl, $slackChannel, $slackUsername) {
        $this->slackWebhookUrl = $slackWebhookUrl;
        $this->slackChannel = $slackChannel;
        $this->slackUsername = $slackUsername;
    }

    public function sendSlackNotification($message, $vietnamMessage = null) {
        $results = [];

        $payload = [
            'channel' => $this->slackChannel,
            'username' => $this->slackUsername,
            'text' => $message,
            'icon_emoji' => ':ghost:'
        ];
        $mainData = ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];

        $results['main'] = $this->postFormUrlEncoded(
            $this->slackWebhookUrl,
            $mainData
        );

        $messageForVietnam = $vietnamMessage ?? $message;
        $results['vietnam'] = $this->sendSlackNotificationVietnam($messageForVietnam);

        return $results;
    }

    public function sendSlackNotificationVietnam($message) {
        $url = trim((string) Config::get('SLACK_VIETNAM_API_URL', ''));
        $apiKey = trim((string) Config::get('SLACK_VIETNAM_API_KEY', ''));
        $channel = Config::get('SLACK_VIETNAM_CHANNEL', 'updates_by_caps');
        $workspace = trim((string) Config::get('SLACK_VIETNAM_WORKSPACE', ''));

        if ($url === '' || $apiKey === '' || $workspace === '') {
            return null;
        }

        $data = [
            'channel' => $channel,
            'workspace' => $workspace,
            'members' => [],
            'message' => $message,
        ];

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->postJson(
            $url,
            $payload,
            [
                'Content-Type: application/json',
                'Api-Key: ' . $apiKey,
            ]
        );
    }

    private function postFormUrlEncoded($url, array $data) {
        return $this->executeCurlRequest($url, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
        ]);
    }

    private function postJson($url, $payload, array $headers) {
        return $this->executeCurlRequest($url, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);
    }

    private function executeCurlRequest($url, array $options) {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }
}
