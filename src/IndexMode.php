<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter;

enum IndexMode: string
{
    case Auto = 'auto';
    case Memory = 'memory';
    case File = 'file';
}
