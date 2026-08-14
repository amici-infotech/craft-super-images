<?php
/**
 * Discriminator for how a source image is identified and resolved.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Source Kind
 *
 * Backing enum for SourceReference and SourceImage indicating asset, local path, or remote URL sources.
 */
enum SourceKind: string
{
    /** Craft asset element resolved via the assets service. */
    case Asset = 'asset';

    /** Local filesystem path within allowed roots. */
    case LocalPath = 'localPath';

    /** Remote HTTP(S) URL fetched with configured limits. */
    case RemoteUrl = 'remoteUrl';
}
