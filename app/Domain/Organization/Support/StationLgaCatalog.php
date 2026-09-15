<?php

namespace App\Domain\Organization\Support;

class StationLgaCatalog
{
    // User-supplied legacy station list (2026-09-11). The placeholder row and legacy numeric IDs are not imported.
    public const STATIONS = [
        'GH AGAIE' => 'AGAIE',
        'GH AUNA' => 'MAGAMA',
        'GH BANGI' => 'MARIGA',
        'GH BIDA' => 'BIDA',
        'GH GULU' => 'LAPAI',
        'JBANM' => 'CHANCHAGA',
        'GH KAFFIN KORO' => 'PAIKORO',
        'GH KAGARA' => 'RAFI',
        'GH KONTAGORA' => 'KONTAGORA',
        'GH KUTA' => 'SHIRORO',
        'GH KUTIGI' => 'LAVUN',
        'GH LAPAI' => 'LAPAI',
        'GH M.I. WUSHISHI' => 'CHANCHAGA',
        'GH MINNA' => 'CHANCHAGA',
        'GH MOKWA' => 'MOKWA',
        'GH NASKO' => 'MAGAMA',
        'GH NEW BUSSA' => 'BORGU',
        'GH SABON WUSE' => 'TAFA',
        'GH SULEJA' => 'SULEJA',
        'GH T/MAGAJIYA' => 'RIJAU',
        'GH TUNGAN YAKUBU' => 'TAFA',
        'GH WUSHISHI' => 'WUSHISHI',
        'GOVT. H. CLINIC' => 'CHANCHAGA',
        'HMB HQTR' => 'CHANCHAGA',
        'IBB SPECIALIST HOSPITAL' => 'CHANCHAGA',
        'MOH HQTR' => 'CHANCHAGA',
        'NICARE HQTRS' => 'CHANCHAGA',
        'PPFN' => 'CHANCHAGA',
        'REHAB. CENTRE' => 'CHANCHAGA',
        'RH GAWU B.' => 'GURARA',
        'SACA HQTRS' => 'CHANCHAGA',
        'SCH OF HEALTH TECH MINNA' => 'CHANCHAGA',
        'SCH OF MIDWIFERY MINNA' => 'CHANCHAGA',
        'SON KONTAGORA' => 'KONTAGORA',
        'SPHCDA' => 'CHANCHAGA',
        'TALBA ESTATE CLINIC' => 'CHANCHAGA',
        'DMA HQTR' => 'CHANCHAGA',
        'NSPHCDA HQTR' => 'CHANCHAGA',
        'GH TAKUTI' => 'LAPAI',
        'H/ASSMEBLY CLINIC' => 'CHANCHAGA',
        'ORPHANAGE' => 'BOSSO',
        'SCHOOL OF HANDICAP' => 'BOSSO',
    ];

    private const ALIASES = [
        'JBAM&NH' => 'JBANM',
        'GH TALBA ESTATE' => 'TALBA ESTATE CLINIC',
        'REHAB.' => 'REHAB. CENTRE',
        'MOH Headquarters' => 'MOH HQTR',
        'HANDICAP' => 'SCHOOL OF HANDICAP',
    ];

    public static function lgaFor(string $name): ?string
    {
        $key = self::normalize($name);
        foreach (self::ALIASES as $alias => $canonical) {
            if (self::normalize($alias) === $key) {
                return self::STATIONS[$canonical];
            }
        }
        foreach (self::STATIONS as $station => $lga) {
            if (self::normalize($station) === $key) {
                return $lga;
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
    }
}
