# queuevoid

One PHP file. No Composer. No network.

`auth()->id()` in a controller is the logged-in user. The same line in a `ShouldQueue` job is `null`. The constructor ran during the web request. `handle()` runs later, on a worker, which has no HTTP request, no session and no authenticated user.

Un fichier PHP. Zéro Composer. Aucun appel réseau.

`auth()->id()` dans un contrôleur, c'est l'utilisateur connecté. La même ligne dans un job `ShouldQueue` vaut `null`. Le constructeur a tourné pendant la requête HTTP. `handle()` tourne plus tard, dans un worker, qui n'a ni requête, ni session, ni utilisateur.

```bash
curl -fsSL -o queuevoid.php \
  https://raw.githubusercontent.com/Nguemoue/project2026_20261029_queuevoid/main/queuevoid.php
php queuevoid.php --self-test
php queuevoid.php /path/to/your/laravel
```

## The bug, precisely

Dispatching a job from a controller feels like a function call. It is not. Laravel serializes the job and a worker process boots it later, often on another machine.

This works, and it is the bug:

```php
class ExportInvoices implements ShouldQueue
{
    public function handle(): void
    {
        $userId = auth()->id();          // null on the worker
        $format = request('format');     // null on the worker
    }
}
```

So does storing the request itself. `Request` does not survive the queue:

```php
public function __construct(public Request $request) {}
```

The constructor is the last moment the HTTP request still exists. Copy scalars there, and give the job `SerializesModels` if you really pass an Eloquent model:

```php
class ExportInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId, public string $format) {}

    public function handle(): void
    {
        Invoice::export($this->userId, $this->format);
    }
}

// still inside the controller
ExportInvoices::dispatch((int) auth()->id(), (string) request('format'));
```

A listener, a notification, a mailable or a closure passed to `dispatch()` has the same hole as soon as it implements `ShouldQueue`. `dispatchSync()` is left alone: that one still runs inside the request.

## What a run looks like

```text
queuevoid 1.0.0
HIGH    stored-request          examples/GhostJobs.php:10
        Request is stored on a queued job. It will not survive the queue. Keep the ids and strings you actually need.
        public function __construct(public Request $request)

HIGH    web-in-worker           examples/GhostJobs.php:16
        auth() runs on the queue worker, where there is no HTTP request, no session and no logged-in user. Read it in the constructor and store a scalar.
        $userId = auth()->id();

HIGH    web-in-worker           examples/GhostJobs.php:17
        request() runs on the queue worker, where there is no HTTP request, no session and no logged-in user. Read it in the constructor and store a scalar.
        $format = request('format');

HIGH    web-in-worker           examples/GhostJobs.php:25
        session() runs on the queue worker, where there is no HTTP request, no session and no logged-in user. Read it in the constructor and store a scalar.
        $email = session('pending_email');

3 files, 4 findings (4 high, 0 medium)
Exit 1: findings at or above high.
```

`examples/GhostJobs.php` is guilty on purpose. `examples/OkJobs.php` is the same feature written so the worker can run it. A controller in that file still calls `request()` and is not reported.

Snippets are source lines. The tool never prints values from `.env`.

## Rules

| Severity | Rule | What it means |
| --- | --- | --- |
| high | `web-in-worker` | `request()`, `auth()`, `session()`, `cookie()`, `csrf_token()`, `back()`, `redirect()`, `old()`, `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` / `$_FILES`, or `Auth::` / `Session::` / `Cookie::` / `Redirect::` / `Request::` inside a queued class, outside `__construct`. The constructor still runs in the original process, so reading the request there is fine. |
| high | `stored-request` | A `Request`, `UploadedFile`, `Closure`, `PDO` or `callable` is promoted or assigned onto `$this`. The queue cannot carry it. |
| high | `injected-request` | `handle(Request $request)` (or the same on any method except `__construct`). The container injects whatever request the worker has, which is empty. |
| high | `queued-closure` | `dispatch(function () { ... })`, `$this->dispatch(function ...)`, `Bus::dispatch(function ...)` or `dispatch(fn () => ...)` closes over the web context. `dispatchSync()` is not flagged. |
| medium | `model-not-serialized` | The constructor takes a class (not a date, a collection or an enum declared in the project) and neither this class nor a parent uses `SerializesModels`. |

A class counts as queued when it `implements ShouldQueue`, or when it `extends` a class that does, including across files. Trait methods are followed when the job `use`s that trait, including a trait on a parent.

`@queuevoid:ignore` is not a thing. Put the marker `queuevoid:ignore` on the line, or on the line above it.

Medium findings are printed and do not fail the process unless you ask. Default CI fails on high only.

## Usage

```bash
php queuevoid.php                      # scan the current directory
php queuevoid.php /var/www/app
php queuevoid.php --json               # stdout is JSON only
php queuevoid.php --fail-on=medium     # also fail on a model missing SerializesModels
php queuevoid.php --fail-on=never      # always exit 0, still print the report
php queuevoid.php --no-color
php queuevoid.php --self-test          # built-in fixtures, no network
```

Exit `0` when nothing reaches the fail level (default: `high`). Exit `1` when something does. Exit `2` when the path or the flags are wrong.

PHP 8.1+ with the `tokenizer` extension, which PHP ships by default. The script tokenizes PHP. It does not `include` your application, so a `dd()` in the project does not run and a syntax error in one file does not abort the scan.

Skipped directories: `vendor/`, `node_modules/`, `.git/`, `storage/`, `bootstrap/cache/`.

## CI

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.3'
    coverage: none
- name: queuevoid
  run: php queuevoid.php --fail-on=high
```

Or, without adding the file to the app:

```yaml
- name: queuevoid
  run: |
      curl -fsSL -o /tmp/queuevoid.php \
        https://raw.githubusercontent.com/Nguemoue/project2026_20261029_queuevoid/main/queuevoid.php
      php /tmp/queuevoid.php .
```

Cloning this repository and running `php queuevoid.php` exits `1`. That is the example under `examples/`, not a bug in the scanner. `php queuevoid.php --self-test` is the check, and it exits `0`.

## Install

**Copy the file.** That is the supported install.

```bash
curl -fsSL -o queuevoid.php \
  https://raw.githubusercontent.com/Nguemoue/project2026_20261029_queuevoid/main/queuevoid.php
```

`composer.json` is included so you can point a path repository at this git URL. The package is not required to be on Packagist.

## What this is not

- Not a full security audit. A helper of your own that wraps `auth()` is invisible. A trait pulled in from `vendor/` is not read, because `vendor/` is skipped.
- `url()` and `route()` are not flagged. Those still work on a worker when `APP_URL` is set. `url()->previous()` does not, and it is not detected.
- Parent classes that live only in `vendor/` are not visible. A job that `extends` a base job shipped by a package, and that never writes `implements ShouldQueue` itself, will be missed. The usual `App\Jobs\Job` in your own tree is followed.
- It does not upload code anywhere. Scanning is local.

## Le trou, en français

Dispatcher un job depuis un contrôleur ressemble à un appel de fonction. Ça n'en est pas un. Laravel sérialise le job, et un worker le reprend plus tard, souvent sur une autre machine.

Le constructeur a encore la requête HTTP. `handle()`, `toMail()`, le listener, le mailable, et la closure passée à `dispatch()` n'ont plus rien :

```php
public function handle(): void
{
    $userId = auth()->id();       // null dans le worker
    $format = request('format');  // null dans le worker
}
```

Garder le `Request` entier ne marche pas non plus. Il ne passe pas la file :

```php
public function __construct(public Request $request) {}
```

Le bon réflexe : copier les scalaires au moment du dispatch, là où `auth()` et `request()` répondent encore.

```php
class ExportInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId, public string $format) {}

    public function handle(): void
    {
        Invoice::export($this->userId, $this->format);
    }
}

ExportInvoices::dispatch((int) auth()->id(), (string) request('format'));
```

`SerializesModels` sert seulement si tu passes vraiment un modèle Eloquent. Sans le trait, le worker stocke une copie figée, souvent impossible à désérialiser. `dispatchSync()` n'est pas signalé : lui s'exécute encore dans la requête.

Le fichier se suffit. `php queuevoid.php --self-test` rejoue les cas limites (constructeur contre `handle()`, propriété promue, trait dans un autre fichier, classe parente, `dispatch(fn () => ...)`, commentaire, chaîne, `vendor/`). Le code scanné n'est jamais exécuté et ne quitte pas la machine.

## License

MIT. See [LICENSE](LICENSE).
