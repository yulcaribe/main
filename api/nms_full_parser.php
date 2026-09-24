<?php
declare(strict_types=1);

function nmsFullDate(mixed $value): ?string {
    $raw = trim((string)($value ?? ''));
    if ($raw === '' || strtoupper($raw) === 'PERM') return null;

    try {
        $utc = new DateTimeZone('UTC');
        if (preg_match('/^\d{12}$/', $raw)) {
            $dt = DateTimeImmutable::createFromFormat('!YmdHi', $raw, $utc);
            return $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d H:i:s') : null;
        }
        if (preg_match('/^\d{14}$/', $raw)) {
            $dt = DateTimeImmutable::createFromFormat('!YmdHis', $raw, $utc);
            return $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d H:i:s') : null;
        }
        $dt = new DateTimeImmutable($raw);
        return $dt->setTimezone($utc)->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function nmsFullClass(?string $value): ?string {
    $value = strtoupper(trim((string)$value));
    if ($value === '') return null;
    return match ($value) {
        'DOM' => 'DOMESTIC',
        'INTL' => 'INTERNATIONAL',
        'MIL' => 'MILITARY',
        'LMIL', 'LOCAL_MIL' => 'LOCAL_MILITARY',
        default => $value,
    };
}

function nmsFullChildText(DOMNode $parent, string $localName): ?string {
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === $localName) {
            $value = trim($child->textContent);
            return $value === '' ? null : $value;
        }
    }
    return null;
}

function nmsFullFirstText(DOMXPath $xp, string $localName, ?DOMNode $context = null): ?string {
    $nodes = $xp->query('.//*[local-name()="' . $localName . '"][1]', $context);
    if (!$nodes || $nodes->length === 0) return null;
    $value = trim((string)$nodes->item(0)?->textContent);
    return $value === '' ? null : $value;
}

function nmsFullNormalizeMessage(string $xml, string $environment): ?array {
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('PHP DOM extension is not enabled.');
    }

    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || !$doc->documentElement) return null;

    $root = $doc->documentElement;
    $nmsId = trim($root->getAttributeNS('http://www.opengis.net/gml/3.2', 'id'));
    if ($nmsId === '') $nmsId = trim($root->getAttribute('gml:id'));
    if ($nmsId === '') return null;

    $xp = new DOMXPath($doc);
    $notams = $xp->query('//*[local-name()="NOTAM"]');
    if (!$notams || $notams->length === 0) return null;
    $notam = $notams->item(0);
    if (!$notam instanceof DOMNode) return null;

    $extensions = $xp->query('//*[local-name()="EventExtension"]');
    $extension = ($extensions && $extensions->length > 0) ? $extensions->item(0) : null;

    $effectiveStartRaw = nmsFullChildText($notam, 'effectiveStart');
    $effectiveEndRaw = nmsFullChildText($notam, 'effectiveEnd');
    $effectiveStart = nmsFullDate($effectiveStartRaw);
    $effectiveEnd = nmsFullDate($effectiveEndRaw);
    $type = nmsFullChildText($notam, 'type');

    $lastUpdatedRaw = $extension instanceof DOMNode
        ? nmsFullFirstText($xp, 'lastUpdated', $extension)
        : null;
    $classificationRaw = $extension instanceof DOMNode
        ? nmsFullFirstText($xp, 'classification', $extension)
        : null;

    return [
        'nms_id' => $nmsId,
        'series' => nmsFullChildText($notam, 'series'),
        'number' => nmsFullChildText($notam, 'number'),
        'year' => nmsStoreInt(nmsFullChildText($notam, 'year')),
        'notam_type' => $type,
        'classification' => nmsFullClass($classificationRaw),
        'affected_fir' => nmsFullChildText($notam, 'affectedFir'),
        'location' => nmsFullChildText($notam, 'location'),
        'icao_location' => $extension instanceof DOMNode ? nmsFullFirstText($xp, 'icaoLocation', $extension) : null,
        'account_id' => $extension instanceof DOMNode ? nmsFullFirstText($xp, 'accountId', $extension) : null,
        'selection_code' => nmsFullChildText($notam, 'selectionCode'),
        'traffic' => nmsFullChildText($notam, 'traffic'),
        'purpose' => nmsFullChildText($notam, 'purpose'),
        'scope' => nmsFullChildText($notam, 'scope'),
        'minimum_fl' => nmsStoreInt(nmsFullChildText($notam, 'minimumFl')),
        'maximum_fl' => nmsStoreInt(nmsFullChildText($notam, 'maximumFl')),
        'effective_start' => $effectiveStart,
        'effective_end' => $effectiveEnd,
        'effective_end_raw' => $effectiveEndRaw,
        'estimated' => nmsFullChildText($notam, 'estimated'),
        'schedule' => nmsFullChildText($notam, 'schedule'),
        'lower_limit' => nmsFullChildText($notam, 'lowerLimit'),
        'upper_limit' => nmsFullChildText($notam, 'upperLimit'),
        'coordinates_raw' => nmsFullChildText($notam, 'coordinates'),
        'radius_nm' => nmsStoreFloat(nmsFullChildText($notam, 'radius')),
        'notam_text' => nmsFullChildText($notam, 'text'),
        'last_updated' => nmsFullDate($lastUpdatedRaw),
        'status' => nmsRecordStatus($type, $effectiveStart, $effectiveEndRaw),
        'source' => 'FAA_NMS',
        'environment' => $environment,
    ];
}
