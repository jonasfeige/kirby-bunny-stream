<?php

declare(strict_types=1);

namespace KirbyBunny\Stream;

/**
 * Static state and constants for Bunny Stream plugin.
 */
class BunnyStreamState
{
    /**
     * Flag to prevent re-entrant hook calls during processing.
     */
    public static bool $processing = false;

    /**
     * Flag to indicate direct upload is in progress (skip normal upload hook).
     */
    public static bool $directUploadInProgress = false;

    // Bunny video status codes
    public const STATUS_QUEUED = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_ENCODING = 2;
    public const STATUS_FINISHED = 3;
    public const STATUS_READY = 4;
    public const STATUS_ERROR = 5;
    public const STATUS_UPLOAD_FAILED = 6;
}
