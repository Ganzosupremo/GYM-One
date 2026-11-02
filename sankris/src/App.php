<?php
declare(strict_types=1);

namespace Sankris;

/** Service container for the Sankris module. */
final class App
{
    private static ?App $instance = null;

    public string $root;
    public array $env;
    public Db $db;
    public Settings $settings;
    public array $translations;
    private ?Auth $auth = null;
    private array $services = [];

    private function __construct(string $root, array $env, Db $db)
    {
        $this->root = $root;
        $this->env = $env;
        $this->db = $db;
        $this->settings = new Settings($db);
        $lang = $env['LANG_CODE'] ?? 'ES';
        $file = $root . '/assets/lang/' . preg_replace('/[^A-Z]/', '', strtoupper($lang)) . '.json';
        $this->translations = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }

    public static function boot(string $root, ?Db $db = null, ?array $env = null): self
    {
        if (self::$instance !== null && $db === null) {
            return self::$instance;
        }
        $env = $env ?? Env::read($root . '/.env');
        $db = $db ?? Db::connect($env['DB_SERVER'] ?? 'localhost', $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', $env['DB_NAME'] ?? '');
        self::$instance = new self($root, $env, $db);
        return self::$instance;
    }

    public function auth(): Auth
    {
        return $this->auth ??= Auth::fromSession($this->db);
    }

    public function setAuth(Auth $auth): void
    {
        $this->auth = $auth;
        $this->services = [];
    }

    public function audit(): Audit
    {
        return $this->services['audit'] ??= new Audit($this->db, $this->auth ? $this->auth->id() : null, $this->auth ? 'staff' : 'system');
    }

    public function currency(): string
    {
        return strtoupper($this->settings->get('currency', 'GTQ'));
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->db, $this->root . '/sankris/migrations');
    }

    public function families(): Families
    {
        return $this->services['families'] ??= new Families($this->db, $this->audit());
    }

    public function schedule(): Schedule
    {
        return $this->services['schedule'] ??= new Schedule($this->db, $this->translations);
    }

    public function credits(): Credits
    {
        return $this->services['credits'] ??= new Credits($this->db, $this->audit());
    }

    public function subscriptions(): Subscriptions
    {
        return $this->services['subscriptions'] ??= new Subscriptions($this->db, $this->audit(), $this->credits());
    }

    public function bookings(): Bookings
    {
        return $this->services['bookings'] ??= new Bookings($this->db, $this->audit(), $this->schedule(), $this->credits());
    }

    public function billing(): Billing
    {
        return $this->services['billing'] ??= new Billing($this->db, $this->audit(), $this->settings, $this->currency());
    }

    public function stripeGateway(): ?Stripe\Gateway
    {
        if (isset($this->services['gateway'])) {
            return $this->services['gateway'];
        }
        $key = $this->env['STRIPE_SECRET_KEY'] ?? '';
        return $key === '' ? null : ($this->services['gateway'] = new Stripe\HttpGateway($key));
    }

    public function setStripeGateway(?Stripe\Gateway $gateway): void
    {
        $this->services['gateway'] = $gateway;
        unset($this->services['stripe']);
    }

    public function stripe(): Stripe\StripeService
    {
        return $this->services['stripe'] ??= new Stripe\StripeService(
            $this->db,
            $this->audit(),
            $this->billing(),
            $this->settings,
            $this->stripeGateway(),
            $this->currency(),
            $this->publicBaseUrl()
        );
    }

    public function publicBaseUrl(): string
    {
        $configured = rtrim($this->settings->get('public_base_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        return '';
    }
}
