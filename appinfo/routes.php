<?php

declare(strict_types=1);

/** Helper function to add a wildcard parameter to the route */
function w($base, $param)
{
    return array_merge($base, [
        'requirements' => [$param => '.*'],
        'defaults' => [$param => ''],
    ]);
}

return [
    'routes' => [
        // Vue routes for deep links
        ['name' => 'Page#main', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'Page#favorites', 'url' => '/favorites', 'verb' => 'GET'],
        ['name' => 'Page#videos', 'url' => '/videos', 'verb' => 'GET'],
        ['name' => 'Page#archive', 'url' => '/archive', 'verb' => 'GET'],
        ['name' => 'Page#trash', 'url' => '/trash', 'verb' => 'GET'],
        ['name' => 'Page#thisday', 'url' => '/thisday', 'verb' => 'GET'],
        ['name' => 'Page#map', 'url' => '/map', 'verb' => 'GET'],
        ['name' => 'Page#explore', 'url' => '/explore', 'verb' => 'GET'],
        ['name' => 'Page#search', 'url' => '/search', 'verb' => 'GET'],
        ['name' => 'Page#nxsetup', 'url' => '/nxsetup', 'verb' => 'GET'],

        // Routes with params
        w(['name' => 'Page#folder', 'url' => '/folders/{path}', 'verb' => 'GET'], 'path'),
        w(['name' => 'Page#albums', 'url' => '/albums/{id}', 'verb' => 'GET'], 'id'),
        w(['name' => 'Page#recognize', 'url' => '/recognize/{name}', 'verb' => 'GET'], 'name'),
        w(['name' => 'Page#facerecognition', 'url' => '/facerecognition/{name}', 'verb' => 'GET'], 'name'),
        w(['name' => 'Page#places', 'url' => '/places/{id}', 'verb' => 'GET'], 'id'),
        w(['name' => 'Page#tags', 'url' => '/tags/{name}', 'verb' => 'GET'], 'name'),

        // Public folder share
        w(['name' => 'Public#showAuthenticate', 'url' => '/s/{token}/authenticate/{redirect}', 'verb' => 'GET'], 'redirect'),
        w(['name' => 'Public#authenticate', 'url' => '/s/{token}/authenticate/{redirect}', 'verb' => 'POST'], 'redirect'),
        w(['name' => 'Public#showShare', 'url' => '/s/{token}/{path}', 'verb' => 'GET'], 'path'),

        // Public album share
        ['name' => 'PublicAlbum#showShare', 'url' => '/a/{token}', 'verb' => 'GET'],
        ['name' => 'PublicAlbum#download', 'url' => '/a/{token}/download', 'verb' => 'GET'],

        // API Routes
        ['name' => 'Days#days', 'url' => '/api/days', 'verb' => 'GET'],
        ['name' => 'Days#day', 'url' => '/api/days', 'verb' => 'POST'],
        ['name' => 'Days#dayGet', 'url' => '/api/days/{id}', 'verb' => 'GET'],
        ['name' => 'Folders#sub', 'url' => '/api/folders/sub', 'verb' => 'GET'],

        ['name' => 'Clusters#list', 'url' => '/api/clusters/{backend}', 'verb' => 'GET'],
        ['name' => 'Clusters#preview', 'url' => '/api/clusters/{backend}/preview', 'verb' => 'GET'],
        ['name' => 'Clusters#setCover', 'url' => '/api/clusters/{backend}/set-cover', 'verb' => 'POST'],
        ['name' => 'Clusters#download', 'url' => '/api/clusters/{backend}/download', 'verb' => 'POST'],

        ['name' => 'Tags#set', 'url' => '/api/tags/set/{id}', 'verb' => 'PATCH'],

        ['name' => 'Map#clusters', 'url' => '/api/map/clusters', 'verb' => 'GET'],
        ['name' => 'Map#init', 'url' => '/api/map/init', 'verb' => 'GET'],

        ['name' => 'Archive#archive', 'url' => '/api/archive/{id}', 'verb' => 'PATCH'],
        ['name' => 'Trash#list', 'url' => '/api/trash/list', 'verb' => 'GET'],

        ['name' => 'Image#preview', 'url' => '/api/image/preview/{id}', 'verb' => 'GET'],
        ['name' => 'Image#multipreview', 'url' => '/api/image/multipreview', 'verb' => 'POST'],
        ['name' => 'Image#info', 'url' => '/api/image/info/{id}', 'verb' => 'GET'],
        ['name' => 'Image#setExif', 'url' => '/api/image/set-exif/{id}', 'verb' => 'PATCH'],
        ['name' => 'Image#reindex', 'url' => '/api/image/reindex/{id}', 'verb' => 'POST'],
        ['name' => 'Image#decodable', 'url' => '/api/image/decodable/{id}', 'verb' => 'GET'],
        ['name' => 'Image#editImage', 'url' => '/api/image/edit/{id}', 'verb' => 'PUT'],
        ['name' => 'Image#deleteFile', 'url' => '/api/image/delete/{id}', 'verb' => 'DELETE'],

        ['name' => 'Video#transcode', 'url' => '/api/video/transcode/{client}/{fileid}/{profile}', 'verb' => 'GET'],
        ['name' => 'Video#storyboard', 'url' => '/api/video/storyboard/{client}/{fileid}/{profile}', 'verb' => 'GET'],
        ['name' => 'Video#livephoto', 'url' => '/api/video/livephoto/{fileid}', 'verb' => 'GET'],

        ['name' => 'Download#request', 'url' => '/api/download', 'verb' => 'POST'],
        ['name' => 'Download#file', 'url' => '/api/download/{handle}', 'verb' => 'GET'],
        ['name' => 'Download#one', 'url' => '/api/stream/{fileid}', 'verb' => 'GET'],

        ['name' => 'Lens#file', 'url' => '/lens/file/{fileid}', 'verb' => 'GET', 'requirements' => ['fileid' => '\d+']],
        ['name' => 'Lens#scan', 'url' => '/lens/scan', 'verb' => 'POST'],
        ['name' => 'Lens#search', 'url' => '/api/lens/search', 'verb' => 'GET'],
        ['name' => 'Lens#similar', 'url' => '/api/lens/similar/{fileid}', 'verb' => 'GET', 'requirements' => ['fileid' => '\d+']],
        ['name' => 'Lens#duplicates', 'url' => '/api/lens/duplicates', 'verb' => 'GET'],

        ['name' => 'People#mergeSuggestions', 'url' => '/api/people-merge/suggestions', 'verb' => 'GET'],
        ['name' => 'People#merge', 'url' => '/api/people-merge', 'verb' => 'POST'],
        ['name' => 'People#mergeDismiss', 'url' => '/api/people-merge/dismiss', 'verb' => 'POST'],
        ['name' => 'People#meta', 'url' => '/api/people/{backend}/{name}', 'verb' => 'GET'],
        ['name' => 'People#setBirthdate', 'url' => '/api/people/{backend}/{name}/birthdate', 'verb' => 'PUT'],

        ['name' => 'Locked#status', 'url' => '/api/locked', 'verb' => 'GET'],
        ['name' => 'Locked#setPin', 'url' => '/api/locked/pin', 'verb' => 'PUT'],
        ['name' => 'Locked#unlock', 'url' => '/api/locked/unlock', 'verb' => 'POST'],
        ['name' => 'Locked#lock', 'url' => '/api/locked/lock', 'verb' => 'POST'],

        ['name' => 'Partners#list', 'url' => '/api/partners', 'verb' => 'GET'],
        ['name' => 'Partners#add', 'url' => '/api/partners', 'verb' => 'POST'],
        ['name' => 'Partners#remove', 'url' => '/api/partners/{partner}', 'verb' => 'DELETE'],
        ['name' => 'Partners#setInTimeline', 'url' => '/api/partners/{owner}/timeline', 'verb' => 'PUT'],

        ['name' => 'Share#links', 'url' => '/api/share/links', 'verb' => 'GET'],
        ['name' => 'Share#createNode', 'url' => '/api/share/node', 'verb' => 'POST'],
        ['name' => 'Share#deleteShare', 'url' => '/api/share/delete', 'verb' => 'POST'],

        // Config
        ['name' => 'Other#setUserConfig', 'url' => '/api/config/{key}', 'verb' => 'PUT'],
        ['name' => 'Other#getUserConfig', 'url' => '/api/config', 'verb' => 'GET'],
        ['name' => 'Other#describeApi', 'url' => '/api/describe', 'verb' => 'GET'],

        ['name' => 'Recognize#apiKey', 'url' => '/api/recognize/api-key', 'verb' => 'GET'],

        // Admin
        ['name' => 'Admin#getSystemStatus', 'url' => '/api/system-status', 'verb' => 'GET'],
        ['name' => 'Admin#getSystemConfig', 'url' => '/api/system-config', 'verb' => 'GET'],
        ['name' => 'Admin#setSystemConfig', 'url' => '/api/system-config/{key}', 'verb' => 'PUT'],
        ['name' => 'Admin#getFailureLogs', 'url' => '/api/failure-logs', 'verb' => 'GET'],
        ['name' => 'Admin#placesSetup', 'url' => '/api/occ/places-setup', 'verb' => 'POST'],

        // Service worker and assets
        ['name' => 'Other#static', 'url' => '/static/{name}', 'verb' => 'GET'],
    ],
];
