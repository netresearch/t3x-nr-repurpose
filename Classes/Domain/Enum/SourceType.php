<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

enum SourceType: string
{
    case Url    = 'url';
    case PdfUrl = 'pdf_url';
    case PdfFal = 'pdf_fal';
}
