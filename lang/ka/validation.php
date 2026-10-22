<?php

/*
 | Only the rules the forms actually use. Anything else falls through to the
 | framework's English file, which is better than a bare "validation.foo".
 */

return [
    'accepted' => ':attribute უნდა დაადასტურო.',
    'after' => ':attribute :date-ის შემდგომი თარიღი უნდა იყოს.',
    'alpha_dash' => ':attribute მხოლოდ ასოებს, ციფრებს, დეფისსა და ქვედა ტირეს შეიცავს.',
    'alpha_num' => ':attribute მხოლოდ ასოებსა და ციფრებს შეიცავს.',
    'array' => ':attribute სია უნდა იყოს.',
    'before' => ':attribute :date-ის წინა თარიღი უნდა იყოს.',
    'boolean' => ':attribute მხოლოდ კი ან არა შეიძლება იყოს.',
    'confirmed' => ':attribute გამეორებისას არ დაემთხვა.',
    'date' => ':attribute სწორი თარიღი არ არის.',
    'decimal' => ':attribute :decimal ათწილადი ნიშნით უნდა იყოს.',
    'different' => ':attribute და :other განსხვავებული უნდა იყოს.',
    'digits' => ':attribute :digits ციფრისგან უნდა შედგებოდეს.',
    'email' => ':attribute სწორი ფორმატით არ არის.',
    'exists' => 'არჩეული :attribute არასწორია.',
    'file' => ':attribute ფაილი უნდა იყოს.',
    'filled' => ':attribute ცარიელი ვერ იქნება.',
    'image' => ':attribute სურათი უნდა იყოს.',
    'in' => 'არჩეული :attribute არასწორია.',
    'integer' => ':attribute მთელი რიცხვი უნდა იყოს.',
    'mimes' => ':attribute :values ტიპის ფაილი უნდა იყოს.',
    'not_in' => 'არჩეული :attribute არასწორია.',
    'numeric' => ':attribute რიცხვი უნდა იყოს.',
    'present' => ':attribute გადმოცემული უნდა იყოს.',
    'prohibited' => ':attribute აკრძალულია.',
    'regex' => ':attribute სწორი ფორმატით არ არის.',
    'required' => ':attribute სავალდებულოა.',
    'required_if' => ':attribute სავალდებულოა, როცა :other :value-ია.',
    'required_with' => ':attribute სავალდებულოა, როცა :values მითითებულია.',
    'same' => ':attribute და :other უნდა ემთხვეოდეს.',
    'string' => ':attribute ტექსტი უნდა იყოს.',
    'unique' => ':attribute უკვე გამოყენებულია.',
    'uploaded' => ':attribute ვერ აიტვირთა.',
    'url' => ':attribute სწორი ბმული უნდა იყოს.',

    'between' => [
        'array' => ':attribute :min-დან :max ერთეულამდე უნდა შეიცავდეს.',
        'file' => ':attribute :min-დან :max კილობაიტამდე უნდა იყოს.',
        'numeric' => ':attribute :min-სა და :max-ს შორის უნდა იყოს.',
        'string' => ':attribute :min-დან :max სიმბოლომდე უნდა იყოს.',
    ],

    'gt' => [
        'array' => ':attribute :value ერთეულზე მეტს უნდა შეიცავდეს.',
        'file' => ':attribute :value კილობაიტზე მეტი უნდა იყოს.',
        'numeric' => ':attribute :value-ზე მეტი უნდა იყოს.',
        'string' => ':attribute :value სიმბოლოზე გრძელი უნდა იყოს.',
    ],

    'gte' => [
        'array' => ':attribute სულ მცირე :value ერთეული უნდა იყოს.',
        'file' => ':attribute სულ მცირე :value კილობაიტი უნდა იყოს.',
        'numeric' => ':attribute სულ მცირე :value უნდა იყოს.',
        'string' => ':attribute სულ მცირე :value სიმბოლო უნდა იყოს.',
    ],

    'lt' => [
        'array' => ':attribute :value ერთეულზე ნაკლებს უნდა შეიცავდეს.',
        'file' => ':attribute :value კილობაიტზე ნაკლები უნდა იყოს.',
        'numeric' => ':attribute :value-ზე ნაკლები უნდა იყოს.',
        'string' => ':attribute :value სიმბოლოზე მოკლე უნდა იყოს.',
    ],

    'lte' => [
        'array' => ':attribute :value ერთეულზე მეტი ვერ იქნება.',
        'file' => ':attribute :value კილობაიტზე მეტი ვერ იქნება.',
        'numeric' => ':attribute :value-ზე მეტი ვერ იქნება.',
        'string' => ':attribute :value სიმბოლოზე გრძელი ვერ იქნება.',
    ],

    'max' => [
        'array' => ':attribute :max ერთეულზე მეტს ვერ შეიცავს.',
        'file' => ':attribute :max კილობაიტზე მეტი ვერ იქნება.',
        'numeric' => ':attribute :max-ზე მეტი ვერ იქნება.',
        'string' => ':attribute :max სიმბოლოზე გრძელი ვერ იქნება.',
    ],

    'min' => [
        'array' => ':attribute სულ მცირე :min ერთეული უნდა იყოს.',
        'file' => ':attribute სულ მცირე :min კილობაიტი უნდა იყოს.',
        'numeric' => ':attribute სულ მცირე :min უნდა იყოს.',
        'string' => ':attribute სულ მცირე :min სიმბოლო უნდა იყოს.',
    ],

    'size' => [
        'array' => ':attribute :size ერთეული უნდა იყოს.',
        'file' => ':attribute :size კილობაიტი უნდა იყოს.',
        'numeric' => ':attribute :size უნდა იყოს.',
        'string' => ':attribute :size სიმბოლო უნდა იყოს.',
    ],

    'attributes' => [
        'a_address' => 'მისამართი',
        'a_label' => 'მისამართის სახელი',
        'a_name' => 'მიმღების სახელი',
        'a_phone' => 'ტელეფონი',
        'address' => 'მისამართი',
        'city_id' => 'ქალაქი',
        'comment' => 'კომენტარი',
        'current_password' => 'მოქმედი პაროლი',
        'delete_password' => 'პაროლი',
        'email' => 'ელფოსტა',
        'login' => 'ელფოსტა ან ტელეფონი',
        'name' => 'სახელი',
        'password' => 'პაროლი',
        'password_confirmation' => 'პაროლის გამეორება',
        'payment' => 'გადახდის მეთოდი',
        'phone' => 'ტელეფონი',
        'qty' => 'რაოდენობა',
        'session_password' => 'პაროლი',
        'terms' => 'წესები და პირობები',
    ],
];
