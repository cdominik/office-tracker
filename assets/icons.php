<?php
/**
 * Small inline SVG pictograms used in the "Desk / Table" column.
 * All use currentColor so they inherit the surrounding text color.
 */

function icon_desk(): string
{
    return <<<SVG
<svg viewBox="0 0 40 40" width="26" height="26" aria-hidden="true">
  <rect x="6" y="7" width="28" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
  <rect x="10" y="10.5" width="8" height="6" rx="1" fill="currentColor" opacity="0.18"/>
  <circle cx="20" cy="29" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/>
</svg>
SVG;
}

function icon_round_table(): string
{
    return <<<SVG
<svg viewBox="0 0 40 40" width="26" height="26" aria-hidden="true">
  <circle cx="20" cy="20" r="10" fill="none" stroke="currentColor" stroke-width="2"/>
  <rect x="16.5" y="2" width="7" height="6" rx="2" fill="currentColor" opacity="0.55"/>
  <rect x="16.5" y="32" width="7" height="6" rx="2" fill="currentColor" opacity="0.55"/>
  <rect x="2" y="16.5" width="6" height="7" rx="2" fill="currentColor" opacity="0.55"/>
  <rect x="32" y="16.5" width="6" height="7" rx="2" fill="currentColor" opacity="0.55"/>
</svg>
SVG;
}

function icon_big_meeting(): string
{
    return <<<SVG
<svg viewBox="0 0 40 40" width="26" height="26" aria-hidden="true">
  <ellipse cx="20" cy="20" rx="14" ry="8" fill="none" stroke="currentColor" stroke-width="2"/>
  <circle cx="20" cy="5.5" r="2.3" fill="currentColor"/>
  <circle cx="9.5" cy="8.5" r="2.3" fill="currentColor"/>
  <circle cx="3" cy="16" r="2.3" fill="currentColor"/>
  <circle cx="3" cy="24" r="2.3" fill="currentColor"/>
  <circle cx="9.5" cy="31.5" r="2.3" fill="currentColor"/>
  <circle cx="20" cy="34.5" r="2.3" fill="currentColor"/>
  <circle cx="30.5" cy="31.5" r="2.3" fill="currentColor"/>
  <circle cx="37" cy="24" r="2.3" fill="currentColor"/>
  <circle cx="37" cy="16" r="2.3" fill="currentColor"/>
  <circle cx="30.5" cy="8.5" r="2.3" fill="currentColor"/>
</svg>
SVG;
}

function icon_calling(): string
{
    return <<<SVG
<svg viewBox="0 0 40 40" width="26" height="26" aria-hidden="true">
  <rect x="10" y="3" width="20" height="34" rx="3" fill="none" stroke="currentColor" stroke-width="2"/>
  <line x1="10" y1="30" x2="30" y2="30" stroke="currentColor" stroke-width="1.5"/>
  <circle cx="20" cy="16" r="6" fill="none" stroke="currentColor" stroke-width="2"/>
  <line x1="20" y1="22" x2="20" y2="26" stroke="currentColor" stroke-width="2"/>
</svg>
SVG;
}

function icon_for_room_line(string $lineKind, ?string $roomType = null): string
{
    switch ($lineKind) {
        case 'desk':
            return icon_desk();
        case 'table':
            return icon_round_table();
        case 'room':
            if ($roomType === 'M') return icon_big_meeting();
            if ($roomType === 'F') return icon_round_table();
            if ($roomType === 'T') return icon_calling();
            return icon_big_meeting();
        default:
            return icon_desk();
    }
}
