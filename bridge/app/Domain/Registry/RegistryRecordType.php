<?php

declare(strict_types=1);

namespace App\Domain\Registry;

/**
 * `Terraria Record Type` Custom Field の Registry 値 (docs/design.md §9.2, §10.1)。
 *
 * Registry と攻略課題は同じ Project 内に同居するため、この値だけが両者を
 * 区別する。空欄は攻略課題 (Mapping 候補)、未知の非空値は invalid record type
 * として無視する (docs/design.md §11) — その判定は Task 06 の責務。
 */
final class RegistryRecordType
{
    public const string VALUE = 'registry';

    private function __construct() {}
}
