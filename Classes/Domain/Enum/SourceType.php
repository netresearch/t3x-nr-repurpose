<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

enum SourceType: string
{
    case Url = 'url';
    case PdfUrl = 'pdf_url';
    case PdfFal = 'pdf_fal';
}
