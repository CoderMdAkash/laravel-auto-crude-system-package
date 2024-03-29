# Laravel Auto Crude Generator

A simple yet efficient CRUD generator for Laravel that automates the creation of controllers, models, migrations, and views (index, create, store, edit, update, delete, show methods). It also links the generated controller to its respective views.

## Installation

```sh
composer require mdakashmia/laravel-auto-crude
```

## Configuration
**Service Provider Registration**
In `config/app.php`, add in `providers` array -

```php
'providers' => [
    // ...
    Akash\LaravelAutoCrude\AutoCrudeServiceProvider::class,
    // ...
],
```

## Use from Console Command Prompt for create Crude
```php
//use the command 
php artisan generate:crude {CrudeName}
```
### Example Crude

```php
//suppose i want create a crude name of - Category, then run command
php artisan generate:crude Category
//suppose i want create a crude name of - Post, then run command
php artisan generate:crude Post
//suppose i want create a crude name of - Comment, then run command
php artisan generate:crude Comment
```



