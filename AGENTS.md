# ELIO — agent guidelines

ქართული ონლაინ-მაღაზია: **Laravel 13 + Livewire 4**, Blade სერვერზე, Vite.
სრული აღწერა — [README.md](README.md). ქვემოთ მხოლოდ ის, რაც კოდში შესვლამდე
უნდა იცოდე.

## სტეკი და გარემო

- PHP **8.3+**, MySQL 8 (dev-ში `.env` → `DB_CONNECTION=mysql`, ბაზა `elio`)
- Livewire 4 — სერვერზე რენდერებული კომპონენტები, React/Vue არ არის
- `mcamara/laravel-localization` — ყველა საჯარო მარშრუტი `{locale}` პრეფიქსშია
- `phpoffice/phpspreadsheet` — მომწოდებლების XLSX ფაილებისთვის
- UI თემა: ადმინში Bootstrap (`resources/dashboard/`), საიტზე საკუთარი CSS.
  **Tailwind არ არის — არ დაამატო.**

```bash
composer dev            # serve + vite + queue + pail
php artisan test        # SQLite :memory:
vendor/bin/pint         # ფორმატი — commit-ამდე აუცილებლად
```

## სადაც ლოგიკა ცხოვრობს

| ფუნქცია | ადგილი |
|---|---|
| კატალოგის query-ები, ბარათის მასივები | `app/Support/Catalog.php` |
| კალათა / wishlist | `app/Services/Cart.php`, `Wishlist.php` (DB, არა session) |
| გადახდები | `app/Services/Payments/` — `PaymentManager` + `Drivers/` |
| მომწოდებლების იმპორტი | `app/Services/Import/` — დრაივერი + Client თითო მომწოდებელზე |
| მიწოდების ფასი | `app/Services/Delivery/DeliveryCalculator.php` |
| ჰარდკოდით ტექსტები, სლაიდები, placeholder სურათები | `app/Support/Store.php` |

კონტროლერები თხელია — რეალური სამუშაო Livewire კომპონენტებსა და `Services/`-ში.

## წესები, რომლებიც არ უნდა დაარღვიო

1. **ფასი ბაზიდან.** კალათის/ფორმის ფასი მომხმარებლის მოთხოვნაა, არა ფაქტი.
   შეკვეთის შექმნისას ფასი თავიდან იკითხება — იხ. `Checkout::createOrder()`.
2. **გადახდას ბანკი ადასტურებს, არა callback.** callback მხოლოდ შეხსენებაა;
   ვერდიქტი ყოველთვის `PaymentDriver::confirm()`-იდან მოდის. `settle()`
   იდემპოტენტურია — მეორე გამოძახება არაფერს ცვლის.
3. **ტექსტი არასოდეს კოდში.** `__('file.key')` + ჩანაწერი **ორივე**
   `lang/ka/` და `lang/en/`-ში. ბაზის `ui_translations` ფაილებს ზემოდან ედება.
4. **თარგმნადი მოდელები** `HasTranslations`-ს იყენებენ — `withTranslation()`
   scope-ის გარეშე query N+1-ს აკეთებს.
5. **ადმინი** `auth` + `can:admin` უკან. ახალ ადმინ-მარშრუტს ჯგუფში ამატებ,
   არ გაიტან გარეთ.
6. **ფული** `money()` helper-ით ჩნდება (`app/helpers.php`), ხელით ფორმატირება არა.
7. **ბრაუზერიდან მოსული კოდი** (payment method, slug, id) ვალიდაციას გადის —
   `exists:` წესი ან ხელით შემოწმება.

## სიფრთხილის ადგილები

- `Order::$fillable` არ მოიცავს ყველა არსებულ სვეტს — ახალ ველზე წერამდე
  შეამოწმე, თორემ `update()` ჩუმად ჩააგდებს.
- `{!! !!}` Blade-ში მხოლოდ იქ, სადაც HTML ჩვენია. პროდუქტის აღწერა
  მომწოდებლიდან მოდის — სანიტაიზაციის გარეშე არ გამოიტანო.
- ფაილის ატვირთვაზე `mimes:` მიუთითე, `image` წესი SVG-ს უშვებს.
- Livewire-ის update route ლოკალიზაციის ჯგუფშია (`routes/web.php`) —
  მარშრუტების შეხებისას შეამოწმე, რომ POST არ გადამისამართდება.
- მიგრაციები `2026_*` თარიღებით არის — ახალი ფაილიც იმავე სქემას მიჰყვეს.

## კოდის სტილი

- PSR-12 / Laravel Pint (`pint.json` არ არის — ნაგულისხმევი `laravel` preset).
- კომენტარი იმას ხსნის **რატომ**, არა რას — არსებული ფაილები ამ ტონს იცავენ,
  გაიმეორე.
- namespace-ის იმპორტები ფაილის თავში, არა inline FQCN.
