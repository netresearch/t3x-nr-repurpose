<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

/**
 * PDF extraction strategy chosen per job.
 * `auto` lets the ingestion service decide per page (text → vision if sparse → layout if tabular).
 */
enum PdfMode: string
{
    case Auto = 'auto';
    case Text = 'text';
    case Vision = 'vision';
    case Tables = 'tables';
}
