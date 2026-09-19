<?php

// Deliberately fails at include time, which is what a model file with a bad
// require or a missing dependency does. The locator has to survive it.

namespace Anorm\Test\Fixtures\Broken;

throw new \RuntimeException('this file cannot be loaded');
