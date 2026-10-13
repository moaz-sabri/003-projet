<?php

declare(strict_types=1);

namespace App\Tools;

use App\Core\Database;
use App\Core\Money;

/**
 * بيانات تجريبية للتجربة السريعة (مطعم/مقهى).
 * لا تُستدعى تلقائيًا — فقط عبر: php bin/console seed:demo
 */
final class DemoSeeder
{
    public static function run(): int
    {
        $categoryIds = [];
        foreach (Database::all('SELECT id, name FROM categories') as $category) {
            $categoryIds[$category['name']] = (int) $category['id'];
        }

        // [الاسم، التصنيف، السعر، الوصف، نباتي، حار، السعرات، تفاصيل]
        $products = [
            ['قهوة عربية', 'المشروبات الساخنة', 12000,
                'قهوة عربية محمّصة على الطريقة التقليدية، تُقدَّم مع ماء الزهر والهيل. تحضيرها يستغرق دقائق معدودة، ويُنصح بشربها ساخنة.',
                true, false, 250, 'ماء زهر، هيل، سكر اختياري'],

            ['إسبريسو', 'المشروبات الساخنة', 25000,
                'جرعة مزدوجة مكثفة من أجود حبوب البن.', false, false, 10, ''],

            ['كابتشينو', 'المشروبات الساخنة', 28000,
                'حجم متوسط', false, false, 180, ''],

            ['شاي بالنعناع', 'المشروبات الساخنة', 18000,
                'كوب كبير', true, false, 180, ''],

            ['شاي بالحليب', 'المشروبات الساخنة', 20000,
                '', false, false, 220, ''],

            ['ماء بارد', 'المشروبات الباردة', 10000,
                'عبوة 500 مل', true, true, 0, ''],

            ['عصير برتقال طازج', 'المشروبات الباردة', 35000,
                'كوب كبير', true, true, 180, ''],

            ['ليموناضة بالنعناع', 'المشروبات الباردة', 32000,
                'كوب كبير', true, false, 220, ''],

            ['مناقيش زعتر', 'الأكل', 45000,
                'عجينة طازجة محشوة بزيت الزيتون البكر والزعتر البلدي، مخبوزة حتى يصبح القشرة ذهبيًا ومقرمشًا من الخارج والطري من الداخل. قطعة تكفي كوجبة خفيفة مع الشاي.',
                true, false, 420, 'دقيق، زيت زيتون، زعتر بلدي، ماء'],

            ['فطيرة سبانخ', 'الأكل', 50000,
                'سبانخ، جبنة، عجين', true, false, 500, ''],

            ['برجر لحم', 'الأكل', 55000,
                'لحم 120 غ، خبز بريوش', false, false, 650, ''],

            ['بيتزا مارغريتا', 'الأكل', 60000,
                'صلصة طماطم، موزاريلا', true, false, 780, ''],

            ['كبة مقلية', 'الأكل', 40000,
                'أربع حبات', false, true, 380, ''],

            ['كنافة نابلسية', 'حلويات', 42000,
                'قشطة، جبنة، فستق', true, false, 300, ''],

            ['كب كيك شوكولاتة', 'حلويات', 38000,
                'حبة واحدة', false, false, 160, ''],

            ['ليموناضة بالنعناع', 'حلويات', 35000,
                'كوب كبير', true, false, 0, ''],
        ];

        $count = 0;
        $sort  = 10;
        foreach ($products as $row) {
            [$name, $categoryName, $price, $description, $vegetarian, $spicy, $calories, $ingredients] = $row;

            Database::insert('products', [
                'category_id'  => $categoryIds[$categoryName] ?? null,
                'name'         => $name,
                'description'  => $description,
                'price_minor'  => Money::parseToMinor((string) $price),
                'image_path'   => '',
                'extra_json'   => json_encode([
                    'vegetarian'  => $vegetarian ? 1 : 0,
                    'spicy'       => $spicy ? 1 : 0,
                    'calories'    => $calories > 0 ? $calories : '',
                    'ingredients' => $ingredients,
                ], JSON_UNESCAPED_UNICODE),
                'is_available' => 1,
                'is_featured'  => 0,
                'sort_order'   => $sort += 10,
            ]);
            $count++;
        }

        // مواقع تجريبية
        if ((int) Database::value('SELECT COUNT(*) FROM locations') === 0) {
            for ($i = 1; $i <= 6; $i++) {
                Database::insert('locations', [
                    'code'       => sprintf('T%02d', $i),
                    'name'       => 'طاولة ' . $i,
                    'type'       => 'indoor',
                    'note'       => '',
                    'is_active'  => 1,
                    'sort_order' => $i * 10,
                ]);
            }
        }

        return $count;
    }
}