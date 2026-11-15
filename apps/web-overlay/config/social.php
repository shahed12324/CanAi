<?php

return [
    // Facebook Graph API version used for Page publishing.  Facebook retires old versions about two years after release — check developers.facebook.com/docs/graph-api/changelog.
    'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v24.0'),

    // YouTube: Google Cloud OAuth client (type "Web application"; authorised redirect URI = APP_URL/app/social/youtube/callback; YouTube Data API v3 enabled).
    'youtube' => [
        'client_id' => env('SOCIAL_YOUTUBE_CLIENT_ID'),
        'client_secret' => env('SOCIAL_YOUTUBE_CLIENT_SECRET'),
    ],
    // One upload costs 1600 of the 10,000 daily API quota units (shared by every workspace of this installation; thumbnails cost 50 more).  Raise only after Google grants a higher quota.
    'youtube_daily_uploads' => (int) env('SOCIAL_YOUTUBE_DAILY_UPLOADS', 5),
    'youtube_daily_uploads_per_workspace' => (int) env('SOCIAL_YOUTUBE_DAILY_UPLOADS_PER_WORKSPACE', 3),

    // Meta ads: how many create-on-Meta attempts one workspace may start per hour (all objects are created PAUSED).
    // Google Ads (read only for now): the platform's developer token (Google Ads API Center of YOUR manager account) and OAuth client (defaults to the YouTube client — same Google Cloud project; enable the Google Ads API there
    // and add APP_URL/app/google-ads/callback to its authorised redirect URIs).  API version: check developers.google.com/google-ads/api/docs/sunset-dates.
    'google_ads' => [
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'client_id' => env('GOOGLE_ADS_CLIENT_ID', env('SOCIAL_YOUTUBE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET', env('SOCIAL_YOUTUBE_CLIENT_SECRET')),
        'version' => env('GOOGLE_ADS_API_VERSION', 'v24'),
    ],

    'ads_launches_per_hour' => (int) env('ADS_LAUNCHES_PER_HOUR', 3),
];
