<?php

declare(strict_types=1);

namespace App\Infrastructure\Lock;

use RuntimeException;

/**
 * 制限時間内に lock を取得できなかった / lock file を用意できなかった
 * (docs/design.md §10.5)。
 *
 * lock を取れないまま Registry を書き込むと AC-11 の冪等性を守れないため、
 * 呼び出し側は書き込みを行わず failed として扱う (fail closed)。
 */
final class LockUnavailableException extends RuntimeException {}
