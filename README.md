# IAPI.GE — ონლაინ ელექტრონიკის მაღაზია

Laravel 13 + Livewire 4, სერვერზე რენდერებული Blade. ორენოვანი (ka/en), ოთხი
გადახდის დრაივერი ქართული ბანკებისთვის, ათი მომწოდებლის იმპორტი და საკუთარი
ადმინ-პანელი.

## გაშვება

მოთხოვნა: **PHP 8.3+**, Composer, Node 20+, MySQL 8 (ან SQLite).

```bash
composer setup   # install → .env → key → sqlite → migrate → npm install → build
composer dev     # artisan serve + Vite + queue + pail ერთ ბრძანებაში
```

```bash
php artisan migrate --seed
```

> `composer setup` SQLite-ზე აეწყობა. MySQL-ისთვის `.env`-ში მიუთითე
> `DB_CONNECTION=mysql` და დანარჩენი `DB_*`, შემდეგ `php artisan migrate --seed`.

სიდერები: ენები, ატრიბუტები, მიწოდების ქალაქები, გადახდის მეთოდები, მომწოდებლები
და დემო-კატალოგი — იხ. [`database/seeders/DatabaseSeeder.php`](database/seeders/DatabaseSeeder.php).

### ადმინი

ადმინ-პანელი `/admin`-ზეა, `auth` + `can:admin` უკან. მომხმარებელს ხელით მიანიჭე:

```bash
php artisan tinker --execute="App\Models\User::where('email','you@example.com')->update(['is_admin'=>true]);"
```

## მარშრუტები

ყველა საჯარო მარშრუტი `{locale}` პრეფიქსშია (mcamara/laravel-localization,
ნაგულისხმევი ენა URL-ში არ ჩანს).

| URL | route | კომპონენტი |
|---|---|---|
| `/` | `home` | `Livewire\Pages\Home` |
| `/catalog/{slug?}` | `catalog` | `Livewire\Pages\Catalog` |
| `/product/{slug}` | `product` | `Livewire\Pages\Product` |
| `/checkout` | `checkout` | `Livewire\Pages\Checkout` |
| `/order/{number}` | `order` | `Livewire\Pages\OrderPlaced` |
| `/account` | `account` | `Livewire\Account\Index` (auth) |
| `/invoice/{number}` | `invoice` | `InvoiceController` |
| `/contact`, `/about`, `/info/{doc?}` | `contact`, `about`, `info` | `PageController` |
| `/password/reset/{token}` | `password.reset` | `Livewire\Auth\ResetPassword` |

ლოკალის გარეშე: `/sitemap.xml`, `/payment/callback/{driver}` (CSRF-ის გარეშე),
`/payment/return/{number}`, `/payment/bog/installment/{number}`,
`/payment/credo/{number}`, `/up` (health).

ადმინი: `/admin` — dashboard, categories, brands, products(+form), mapping,
attributes, orders(+show), callbacks, users(+show).

## სტრუქტურა

```
app/
  Livewire/
    Pages/{Home,Catalog,Product,Checkout,OrderPlaced}   ← full-page კომპონენტები
    Admin/{Dashboard,Categories,Brands,Products,Orders,Users,Attributes,Mapping,Callbacks}
    Product/{BuyBox,Bundle} · Forms/{CallbackForm,ContactForm,Newsletter}
    AuthModal · CartDrawer · HeaderSearch · WishlistHeart · WishlistCount
  Models/                      ← ~35 მოდელი; თარგმანები *Translation ცხრილებში
    Concerns/HasTranslations.php   ← withTranslation() scope-ი
  Services/
    Cart.php · Wishlist.php · InteractionLog.php
    Payments/     ← PaymentManager + Drivers/{BogCard,BogInstallment,CredoInstallment,TbcInstallment}
    Import/       ← ImportManager, ProductImporter, TaxonomyResolver, ImageDownloader
                     Drivers/{Alta,Alneo,Allmarket,Elite,Ingco,Kontakt,Metromart,Midea,Zoommer}
    Delivery/DeliveryCalculator.php   ← წონა/მოცულობა → ტარიფი ქალაქზე
    Feeds/FacebookFeed.php
  Support/
    Catalog.php                ← კატალოგის query-ები და ბარათის მასივები (view-ების კონტრაქტი)
    Store.php                  ← ჯერ კიდევ ჰარდკოდით: info-გვერდები, სლაიდები, ბანერები, placeholder სურათები
    Translation/DatabaseTranslationLoader.php
    XlsxReader.php · Slug.php
  helpers.php                  ← money(4780) → "4 780 ₾"
  Console/Commands/            ← იხ. ქვემოთ
lang/{ka,en}/                  ← ფაილები; `ui_translations` ცხრილი ზემოდან ედება
resources/
  css/app.css + css/pages/{catalog,product,checkout,info}.css
  dashboard/                   ← ადმინის Bootstrap-ზე დაფუძნებული თემა
  js/core.js · js/app.js · js/ui.js · js/pages/*
  views/livewire/** · views/components/** · views/emails/** · views/invoices/**
```

## ენები

ორშრიანი: `lang/{locale}/*.php` ფაილები, ზემოდან — `ui_translations` ცხრილის
სტრიქონები ([`DatabaseTranslationLoader`](app/Support/Translation/DatabaseTranslationLoader.php)),
ანუ ტექსტი ადმინიდანაც იცვლება კოდის შეხების გარეშე. აქტიური ენები `languages`
ცხრილშია და `AppServiceProvider::useDatabaseLanguages()` მათ localization-ის
პაკეტს აწვდის — `config/laravellocalization.php` მხოლოდ ცარიელ ბაზაზე მუშაობს.

## გადახდები

`PaymentManager` ირჩევს დრაივერს `payment_methods.driver`-ის მიხედვით:

| driver | რა არის |
|---|---|
| `bog-card` | BOG ბარათით — redirect ბანკის გვერდზე |
| `bog-installment` | BOG განვადება — ბანკის კალკულატორი მოდალში |
| `credo-installment` | Credo განვადება |
| `tbc-installment` | TBC განვადება |

callback მხოლოდ „შეხსენებაა" — ვერდიქტს ყოველთვის ბანკს თვითონ ვეკითხებით
(`PaymentDriver::confirm()`), ასე რომ გაყალბებული POST შეკვეთას გადახდილად ვერ
აქცევს. `payments:check` ქრონი ყოველ 5 წუთში გადაამოწმებს დაკარგულ callback-ებს.

კონფიგი: `config/{bog,tbc,credo}.php`, გასაღებები `.env`-ში.

## ბრძანებები

```bash
php artisan payments:check [--hours=48]   # დაუდასტურებელი გადახდების გადამოწმება (დაგეგმილია)
php artisan import:all                    # ყველა მომწოდებლის სრული იმპორტი
php artisan import:run                    # ერთი მომწოდებელი
php artisan import:stock [supplier] [--all]
php artisan import:sync-stock {supplier}
php artisan import:file                   # XLSX/CSV ფაილიდან
php artisan import:stock-file
php artisan feed:generate [--facebook]    # Facebook/Meta პროდუქტ-ფიდი
```

## კონვენციები

- **აიქონი:** `<x-icon name="shopping-bag" size="18" />` — ფერი `currentColor`-იდან.
- **პროდუქტის ბარათი:** `<x-product-card :product="$p" />` (ან `variant="deal"`).
- **ბარათის მასივი:** view-ები `Support\Catalog::card()`-ის გასაღებებს ენდობიან —
  ახალი წყარო იგივე ფორმა უნდა დააბრუნოს.
- **გვერდის CSS/JS:** `@push('styles') @vite('resources/css/pages/x.css') @endpush`;
  ახალი entry `vite.config.js`-შიც დაამატე.
- **ფასები:** კალათის ფასი მხოლოდ მინიშნებაა — შეკვეთის შექმნისას ფასი ბაზიდან
  თავიდან იკითხება (`Checkout::createOrder()`).
- **ფორმატი:** `vendor/bin/pint` commit-ამდე.

## ტესტები

```bash
php artisan test        # SQLite :memory:
vendor/bin/pint --test  # ფორმატის შემოწმება
```
