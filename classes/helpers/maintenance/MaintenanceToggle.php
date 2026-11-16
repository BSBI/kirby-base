<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * One checkbox on a task's card, such as "Dry run", whose state is sent with Run and
 * reaches the task as {@see MaintenanceOptions::$toggle}.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MaintenanceToggle
{
    /**
     * @param string $label the checkbox label, e.g. "Dry run"
     * @param string $help what ticking and unticking it means, shown under it
     * @param bool $default whether it starts ticked
     * @param string $confirmOn the question asked before Run while it is ticked
     * @param string $confirmOff the question asked before Run while it is unticked
     * @param string|null $blockedOffReason when set, Run is disabled while it is
     *        unticked, and this says why (e.g. "Run a dry run first")
     */
    public function __construct(
        public string $label,
        public string $help,
        public bool $default,
        public string $confirmOn,
        public string $confirmOff,
        public ?string $blockedOffReason = null,
    ) {
    }

    /**
     * @return array{label: string, help: string, default: bool, confirmOn: string, confirmOff: string, blockedOffReason: string|null}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'help' => $this->help,
            'default' => $this->default,
            'confirmOn' => $this->confirmOn,
            'confirmOff' => $this->confirmOff,
            'blockedOffReason' => $this->blockedOffReason,
        ];
    }
}
