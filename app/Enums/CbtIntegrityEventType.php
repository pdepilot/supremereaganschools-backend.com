<?php

namespace App\Enums;

enum CbtIntegrityEventType: string
{
    case TabHidden = 'tab_hidden';
    case WindowBlur = 'window_blur';
    case FullscreenExit = 'fullscreen_exit';
    case FullscreenUnavailable = 'fullscreen_unavailable';
    case AutoSubmitTriggered = 'auto_submit_triggered';
    case AutoSubmitCompleted = 'auto_submit_completed';
    case AutoSubmitFailed = 'auto_submit_failed';
}
