<?php
declare(strict_types=1);

/**
 * Stable Iran province catalog (31 provinces).
 * Codes must match SVG data-province attributes and TS catalog.
 *
 * @return list<array{code:string,name:string}>
 */
function iran_provinces(): array
{
    return [
        ['code' => 'east-azarbaijan', 'name' => 'آذربایجان شرقی'],
        ['code' => 'west-azarbaijan', 'name' => 'آذربایجان غربی'],
        ['code' => 'ardabil', 'name' => 'اردبیل'],
        ['code' => 'isfahan', 'name' => 'اصفهان'],
        ['code' => 'alborz', 'name' => 'البرز'],
        ['code' => 'ilam', 'name' => 'ایلام'],
        ['code' => 'bushehr', 'name' => 'بوشهر'],
        ['code' => 'tehran', 'name' => 'تهران'],
        ['code' => 'chaharmahal', 'name' => 'چهارمحال و بختیاری'],
        ['code' => 'south-khorasan', 'name' => 'خراسان جنوبی'],
        ['code' => 'razavi-khorasan', 'name' => 'خراسان رضوی'],
        ['code' => 'north-khorasan', 'name' => 'خراسان شمالی'],
        ['code' => 'khuzestan', 'name' => 'خوزستان'],
        ['code' => 'zanjan', 'name' => 'زنجان'],
        ['code' => 'semnan', 'name' => 'سمنان'],
        ['code' => 'sistan', 'name' => 'سیستان و بلوچستان'],
        ['code' => 'fars', 'name' => 'فارس'],
        ['code' => 'qazvin', 'name' => 'قزوین'],
        ['code' => 'qom', 'name' => 'قم'],
        ['code' => 'kurdistan', 'name' => 'کردستان'],
        ['code' => 'kerman', 'name' => 'کرمان'],
        ['code' => 'kermanshah', 'name' => 'کرمانشاه'],
        ['code' => 'kohgiluyeh', 'name' => 'کهگیلویه و بویراحمد'],
        ['code' => 'golestan', 'name' => 'گلستان'],
        ['code' => 'gilan', 'name' => 'گیلان'],
        ['code' => 'lorestan', 'name' => 'لرستان'],
        ['code' => 'mazandaran', 'name' => 'مازندران'],
        ['code' => 'markazi', 'name' => 'مرکزی'],
        ['code' => 'hormozgan', 'name' => 'هرمزگان'],
        ['code' => 'hamadan', 'name' => 'همدان'],
        ['code' => 'yazd', 'name' => 'یزد'],
    ];
}

/**
 * @return array<string, string> code => Persian name
 */
function iran_provinces_map(): array
{
    $out = [];
    foreach (iran_provinces() as $p) {
        $out[$p['code']] = $p['name'];
    }
    return $out;
}

function iran_province_name(string $code): ?string
{
    $map = iran_provinces_map();
    return $map[$code] ?? null;
}

/**
 * Major city (and common nicknames) → province code.
 * Used by smart search even when CMS branch.city spelling differs.
 *
 * @return array<string, string> city label => province code
 */
function iran_city_aliases(): array
{
    return [
        'تهران' => 'tehran',
        'اسلامشهر' => 'tehran',
        'ورامین' => 'tehran',
        'کرج' => 'alborz',
        'فردیس' => 'alborz',
        'هشتگرد' => 'alborz',
        'اصفهان' => 'isfahan',
        'کاشان' => 'isfahan',
        'نجف‌آباد' => 'isfahan',
        'مشهد' => 'razavi-khorasan',
        'نیشابور' => 'razavi-khorasan',
        'سبزوار' => 'razavi-khorasan',
        'شیراز' => 'fars',
        'مرودشت' => 'fars',
        'تبریز' => 'east-azarbaijan',
        'مراغه' => 'east-azarbaijan',
        'ارومیه' => 'west-azarbaijan',
        'خوی' => 'west-azarbaijan',
        'اهواز' => 'khuzestan',
        'آبادان' => 'khuzestan',
        'دزفول' => 'khuzestan',
        'خرمشهر' => 'khuzestan',
        'رشت' => 'gilan',
        'انزلی' => 'gilan',
        'لاهیجان' => 'gilan',
        'ساری' => 'mazandaran',
        'بابل' => 'mazandaran',
        'آمل' => 'mazandaran',
        'قائمشهر' => 'mazandaran',
        'کرمان' => 'kerman',
        'رفسنجان' => 'kerman',
        'سیرجان' => 'kerman',
        'بندرعباس' => 'hormozgan',
        'قشم' => 'hormozgan',
        'کیش' => 'hormozgan',
        'قم' => 'qom',
        'قزوین' => 'qazvin',
        'یزد' => 'yazd',
        'اردکان' => 'yazd',
        'همدان' => 'hamadan',
        'ملایر' => 'hamadan',
        'اراک' => 'markazi',
        'ساوه' => 'markazi',
        'خرم‌آباد' => 'lorestan',
        'بروجرد' => 'lorestan',
        'کرمانشاه' => 'kermanshah',
        'سنندج' => 'kurdistan',
        'سقز' => 'kurdistan',
        'گرگان' => 'golestan',
        'گنبد' => 'golestan',
        'سمنان' => 'semnan',
        'شاهرود' => 'semnan',
        'زنجان' => 'zanjan',
        'اردبیل' => 'ardabil',
        'بوشهر' => 'bushehr',
        'عسلویه' => 'bushehr',
        'زاهدان' => 'sistan',
        'چابهار' => 'sistan',
        'بیرجند' => 'south-khorasan',
        'بجنورد' => 'north-khorasan',
        'شهرکرد' => 'chaharmahal',
        'یاسوج' => 'kohgiluyeh',
        'ایلام' => 'ilam',
        'tehran' => 'tehran',
        'isfahan' => 'isfahan',
        'mashhad' => 'razavi-khorasan',
        'shiraz' => 'fars',
        'tabriz' => 'east-azarbaijan',
        'karaj' => 'alborz',
    ];
}
