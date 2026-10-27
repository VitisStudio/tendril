<?php

namespace VitisStudio\Tendril\Core\Contracts;

use VitisStudio\Tendril\Core\Diagnostics\CheckResult;

interface DiagnosticCheck
{
    public function name(): string;

    public function run(): CheckResult;
}
