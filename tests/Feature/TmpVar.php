<?php
namespace Tests\Feature;
use App\Models\{Attribute, AttributeValue, Category, Language, Product};
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
class TmpVar extends TestCase
{
    use RefreshDatabase;
    public function test_probe(): void
    {
        Language::insert([['code'=>'ka','name'=>'G','native_name'=>'ქ','is_active'=>true,'is_default'=>true,'sort_order'=>1]]);
        Language::flushCache();
        $c = Category::create(['parent_id'=>null,'is_active'=>true,'sort_order'=>1]);
        $c->saveTranslations(['ka'=>['name'=>'ტ','slug'=>'t']]);

        $attr = Attribute::create(['code'=>'feri','is_filterable'=>true,'is_variant'=>true,'sort_order'=>1]);
        $attr->saveTranslations(['ka'=>['name'=>'ფერი']]);

        $mk = function ($name,$slug,$price) use ($c) {
            $p = Product::create(['sku'=>'S-'.$slug,'category_id'=>$c->id,'price'=>$price,'stock'=>5,
                'status'=>Product::STATUS_ACTIVE,'published_at'=>now()->subHour(),'variant_group'=>'g1']);
            $p->saveTranslations(['ka'=>['name'=>$name,'slug'=>$slug]]);
            return $p->fresh();
        };
        $black = $mk('შავი','black',900);
        $blue  = $mk('ლურჯი','blue',800);

        $give = function ($p,$code,$label,$hex) use ($attr) {
            $v = AttributeValue::firstOrCreate(['attribute_id'=>$attr->id,'code'=>$code],['sort_order'=>0,'color_hex'=>$hex]);
            $v->saveTranslations(['ka'=>['label'=>$label]]);
            $p->attributeValues()->syncWithoutDetaching([$v->id]);
        };
        $give($black,'shavi','შავი','#000');
        $give($blue,'lurji','ლურჯი','#00F');

        $page = Catalog::productPage($black->fresh());
        fwrite(STDERR, "\nCOLORS: ".json_encode($page['colors'], JSON_UNESCAPED_UNICODE)."\n");

        $r = Livewire::test(\App\Livewire\Product\BuyBox::class, ['product'=>$black->fresh(),'colors'=>$page['colors'],'configs'=>$page['configs']])
            ->call('chooseColor','lurji');
        fwrite(STDERR, "REDIRECTED: ".var_export($r->effects['redirect'] ?? null, true)."\n");
        $this->assertTrue(true);
    }
}
