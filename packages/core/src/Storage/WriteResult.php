<?php

namespace VitisStudio\Tendril\Core\Storage;

final class WriteResult
{
    public int $inserted = 0;

    public int $duplicates = 0;

    public int $failed = 0;

    public int $operationsFailed = 0;
}
