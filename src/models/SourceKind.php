<?php

namespace amici\SuperImages\models;

enum SourceKind: string
{
    case Asset = 'asset';
    case LocalPath = 'localPath';
    case RemoteUrl = 'remoteUrl';
}
