# ELIO — Blade front-end

HTML პროტოტიპი გადატანილია Laravel 13-ზე: მონაცემები PHP-შია, მარკაპი Blade კომპონენტებად
სერვერზე რენდერდება, JS მხოლოდ ქცევას მართავს (Vite, ES modules).

## გაშვება

მოთხოვნა: **PHP 8.3+**, Composer, Node 20+.

```bash
composer setup   # composer install → .env → key → sqlite → migrate → npm install → build
composer dev     # სერვერი + Vite ერთ ბრძანებაში
```

გახსენი `http://localhost:8000`.

ეს უკვე სრული Laravel 13 პროექტია — ELIO-ს ფაილები ჩასმულია, `app/helpers.php`
`composer.json`-ის autoload-შია, Tailwind და welcome გვერდი ამოღებულია.

## მარშრუტები

| URL | route | view |
|---|---|---|
| `/` | `home` | `pages.home` |
| `/catalog` | `catalog` | `pages.catalog` |
| `/product` | `product` | `pages.product` |
| `/contact` | `contact` | `pages.info` |
| `/about` | `about` | `pages.info` |
| `/info/{doc?}` | `info` | `pages.info` — `delivery`, `returns`, `warranty`, `installments` |

## სტრუქტურა

```
app/
  Support/Store.php            ← დემო მონაცემები (ჩაანაცვლე Eloquent-ით)
  helpers.php                  ← money(4780) → "4 780 ₾"
resources/
  css/app.css                  ← ტოკენები, reset, ჰედერი, კარტები, drawer-ები, ფუტერი, მთავარი, მობაილი
  css/pages/{catalog,product,info}.css
  js/core.js                   ← საერთო მდგომარეობა: კალათა, wishlist, პანელები, toast
  js/app.js                    ← ყველა გვერდის ქცევა
  js/pages/{home,catalog,product,info}.js
  views/
    layouts/app.blade.php
    pages/{home,catalog,product,info}.blade.php
    components/
      icon · product-card · page-bar
      icons/sprite                           ← Phosphor (MIT), 33 სიმბოლო
      layout/{topbar,header,nav,footer,tabbar,cookies,auth-modal,auth-socials}
      drawers/{catalog,cart}
      home/{hero,promo,category-section,editorial,brands,perks}
      catalog/{sidebar,filter-group,chips}
      product/{gallery,info,bundle,buy-card,buy-row,tabs,consult}
      info/{tabs,contact,about,document}
public/
  fonts/HNGEO.woff2, HNGEOCaps.woff2
  img/brands/*.png                           ← 20 ლოგო
```

## კონვენციები

- **აიქონი:** `<x-icon name="shopping-bag" size="18" />` — ფერი `currentColor`-იდან მოდის.
- **პროდუქტის კარტი:** `<x-product-card :product="$p" />` (ან `variant="deal"`).
  დამატებითი ატრიბუტები (`data-brand` …) პირდაპირ `<article>`-ზე გადადის.
- **გვერდის CSS/JS:** `@push('styles') @vite('resources/css/pages/x.css') @endpush`,
  იგივე `@push('scripts')`-ით. ახალი entry `vite.config.js`-შიც დაამატე.
- **ფორმატი:** მასივების ფორმა `Store::product()`-შია აღწერილი — Eloquent-ზე გადასვლისას
  resource/DTO-მ იგივე გასაღებები დააბრუნოს და view-ები არ შეიცვლება.

## Livewire-ზე გადასატანი ადგილები

| დღეს (JS) | სად | რად უნდა იქცეს |
|---|---|---|
| კალათა `localStorage`-ში | `core.js` → `cart` | `Livewire\Cart` + session/DB |
| wishlist `localStorage`-ში | `core.js` → `wishlist` | ავტორიზებული მომხმარებლის ცხრილი |
| კატალოგის ფილტრები DOM-ზე | `pages/catalog.js` | Livewire კომპონენტი query-string-ით |
| ძებნა `window.ELIO.search`-ზე | `app.js` | Scout / DB ძებნა |
| ავტორიზაცია / საკონტაქტო ფორმა | `auth-modal`, `info/contact` | ფორმები უკვე `@csrf`-ით და `name`-ებით |
