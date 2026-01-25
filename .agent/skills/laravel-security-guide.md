# Laravel Backend Security & Structure Best Practices

## Skill Overview
This skill provides comprehensive guidance for implementing secure Laravel backend applications following OWASP Top 10 best practices, proper project structure, and when to use specific Laravel components (Policies, Resources, Contracts, Services, etc.).

## Core Capabilities

### 1. Security Implementation
- OWASP Top 10 vulnerability protection
- Authentication and authorization patterns
- Input validation and sanitization
- SQL injection prevention
- XSS and CSRF protection
- Cryptographic best practices
- Security logging and monitoring

### 2. Project Architecture
- When to create Policies vs Gates
- Form Request validation patterns
- API Resource transformations
- Service layer implementation
- Contract/Interface usage
- Repository pattern (optional)
- DTO implementation

### 3. Code Generation
Generate secure, production-ready Laravel code including:
- Policies with proper authorization logic
- Form Requests with comprehensive validation
- API Resources with data transformation
- Services with business logic separation
- Middleware for security headers
- Secure controllers with authorization

---

## OWASP Top 10 Protection Patterns

### A01: Broken Access Control

**Policy Pattern:**
```php
// app/Policies/PostPolicy.php
<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return $post->published || $user->id === $post->user_id;
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->user_id || $user->hasRole('admin');
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->id === $post->user_id || $user->hasRole('admin');
    }

    public function forceDelete(User $user, Post $post): bool
    {
        return $user->hasRole('admin');
    }
}
```

**Controller with Authorization:**
```php
// app/Http/Controllers/PostController.php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;

class PostController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Post::class);
        
        $posts = Post::with(['user'])
            ->when(!auth()->user()?->hasRole('admin'), function ($query) {
                $query->where('published', true);
            })
            ->paginate(15);
        
        return PostResource::collection($posts);
    }

    public function store(StorePostRequest $request)
    {
        $this->authorize('create', Post::class);
        
        $post = auth()->user()->posts()->create($request->validated());
        
        return new PostResource($post);
    }

    public function show(Post $post)
    {
        $this->authorize('view', $post);
        
        return new PostResource($post->load(['user', 'comments']));
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $this->authorize('update', $post);
        
        $post->update($request->validated());
        
        return new PostResource($post);
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);
        
        $post->delete();
        
        return response()->json(['message' => 'Post deleted successfully']);
    }
}
```

**Gate Pattern (for non-resource checks):**
```php
// app/Providers/AuthServiceProvider.php
use Illuminate\Support\Facades\Gate;

Gate::define('access-admin-panel', function (User $user) {
    return $user->hasRole('admin');
});

Gate::define('export-users', function (User $user) {
    return $user->hasPermission('export-data');
});

// Usage in controller
if (Gate::denies('access-admin-panel')) {
    abort(403);
}
```

### A02: Cryptographic Failures

**Model with Encryption:**
```php
// app/Models/User.php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];
    
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];
    
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'ssn' => 'encrypted',  // Auto encrypt/decrypt
        'metadata' => 'encrypted:array',
    ];
}
```

**Security Headers Middleware:**
```php
// app/Http/Middleware/SecurityHeaders.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        
        return $response;
    }
}
```

### A03: Injection Prevention

**Form Request with Validation:**
```php
// app/Http/Requests/StorePostRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy handles this
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255|min:3',
            'slug' => 'required|string|max:255|unique:posts,slug|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'content' => 'required|string|min:10',
            'excerpt' => 'nullable|string|max:500',
            'published' => 'boolean',
            'publish_at' => 'nullable|date|after:now',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Please provide a title for your post.',
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Sanitize input before validation
        $this->merge([
            'slug' => \Str::slug($this->slug),
            'published' => $this->boolean('published'),
        ]);
    }
}
```

**Safe Database Queries:**
```php
// ✅ CORRECT: Using Eloquent ORM
$users = User::where('email', $request->email)
    ->where('status', 'active')
    ->get();

// ✅ CORRECT: Query Builder with bindings
$users = DB::table('users')
    ->where('email', '=', $request->email)
    ->get();

// ✅ CORRECT: Raw query with parameter binding
$users = DB::select('SELECT * FROM users WHERE email = ? AND status = ?', [
    $request->email,
    'active'
]);

// ❌ WRONG: String concatenation (SQL Injection vulnerable)
$users = DB::select("SELECT * FROM users WHERE email = '{$request->email}'");
```

### A04: Insecure Design - Service Layer Pattern

**Service with Business Logic:**
```php
// app/Services/UserRegistrationService.php
<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class UserRegistrationService
{
    public function register(array $data): User
    {
        // Check rate limiting
        $key = 'register:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            throw new \Exception("Too many registration attempts. Try again in {$seconds} seconds.");
        }
        
        RateLimiter::hit($key, 60);
        
        DB::beginTransaction();
        try {
            // Create user
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            
            // Assign default role
            $user->assignRole('user');
            
            // Send verification email
            $user->sendEmailVerificationNotification();
            
            // Send welcome notification
            $user->notify(new WelcomeNotification());
            
            // Log registration
            Log::channel('audit')->info('User registered', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => request()->ip(),
            ]);
            
            DB::commit();
            
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('User registration failed', [
                'email' => $data['email'],
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
```

**Contract/Interface Pattern:**
```php
// app/Contracts/PaymentGatewayInterface.php
<?php

namespace App\Contracts;

use App\DTOs\PaymentResult;

interface PaymentGatewayInterface
{
    public function charge(float $amount, array $metadata = []): PaymentResult;
    public function refund(string $transactionId, float $amount): bool;
    public function getTransaction(string $transactionId): ?array;
}

// app/Services/Payment/StripePaymentGateway.php
<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentResult;
use Stripe\StripeClient;

class StripePaymentGateway implements PaymentGatewayInterface
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    public function charge(float $amount, array $metadata = []): PaymentResult
    {
        $paymentIntent = $this->stripe->paymentIntents->create([
            'amount' => $amount * 100, // Convert to cents
            'currency' => 'usd',
            'metadata' => $metadata,
            'idempotency_key' => $metadata['idempotency_key'] ?? uniqid(),
        ]);

        return new PaymentResult(
            success: $paymentIntent->status === 'succeeded',
            transactionId: $paymentIntent->id,
            amount: $amount,
            metadata: $paymentIntent->metadata->toArray()
        );
    }

    public function refund(string $transactionId, float $amount): bool
    {
        $refund = $this->stripe->refunds->create([
            'payment_intent' => $transactionId,
            'amount' => $amount * 100,
        ]);

        return $refund->status === 'succeeded';
    }

    public function getTransaction(string $transactionId): ?array
    {
        return $this->stripe->paymentIntents->retrieve($transactionId)->toArray();
    }
}

// Bind in AppServiceProvider
use App\Contracts\PaymentGatewayInterface;
use App\Services\Payment\StripePaymentGateway;

$this->app->bind(PaymentGatewayInterface::class, StripePaymentGateway::class);
```

### A07: Authentication Failures

**Strong Authentication Configuration:**
```php
// config/auth.php
'passwords' => [
    'users' => [
        'provider' => 'users',
        'table' => 'password_reset_tokens',
        'expire' => 60,
        'throttle' => 60, // 1 minute throttle
    ],
],

// app/Http/Requests/Auth/RegisterRequest.php
use Illuminate\Validation\Rules\Password;

public function rules(): array
{
    return [
        'name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users',
        'password' => [
            'required',
            'confirmed',
            Password::min(8)
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised(), // Check against data breaches
        ],
    ];
}
```

**Rate Limiting:**
```php
// app/Providers/RouteServiceProvider.php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->email . $request->ip());
});

RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

// Usage in routes
Route::middleware(['throttle:login'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});
```

### A09: Security Logging and Monitoring

**Audit Logger Service:**
```php
// app/Services/AuditLogger.php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AuditLogger
{
    private function log(string $level, string $message, array $context = []): void
    {
        $context = array_merge([
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ], $context);

        Log::channel('audit')->{$level}($message, $context);
    }

    public function logLogin(int $userId): void
    {
        $this->log('info', 'User logged in', ['user_id' => $userId]);
    }

    public function logFailedLogin(string $email): void
    {
        $this->log('warning', 'Failed login attempt', ['email' => $email]);
    }

    public function logDataAccess(string $resource, int $resourceId): void
    {
        $this->log('info', 'Data accessed', [
            'resource' => $resource,
            'resource_id' => $resourceId,
        ]);
    }

    public function logDataModification(string $action, string $resource, int $resourceId, array $changes = []): void
    {
        $this->log('info', "Data {$action}", [
            'action' => $action,
            'resource' => $resource,
            'resource_id' => $resourceId,
            'changes' => $changes,
        ]);
    }

    public function logSecurityEvent(string $event, array $context = []): void
    {
        $this->log('warning', "Security event: {$event}", $context);
    }
}

// Usage in Controller
public function update(UpdateUserRequest $request, User $user)
{
    $changes = $user->getDirty();
    $user->update($request->validated());
    
    app(AuditLogger::class)->logDataModification('update', 'user', $user->id, $changes);
    
    return new UserResource($user);
}
```

---

## API Resource Pattern

**Resource with Conditional Fields:**
```php
// app/Http/Resources/UserResource.php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
            'role' => $this->role,
            'created_at' => $this->created_at->toIso8601String(),
            
            // Conditional fields
            'email_verified' => $this->when(
                $request->user()?->hasRole('admin'),
                $this->email_verified_at !== null
            ),
            
            // Load relationships conditionally
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            
            // Authorization checks
            'permissions' => [
                'can_edit' => $request->user()?->can('update', $this->resource),
                'can_delete' => $request->user()?->can('delete', $this->resource),
            ],
            
            // Never expose these
            // - password
            // - remember_token
            // - api_token
            // - two_factor_secret
        ];
    }
}
```

---

## DTO Pattern

**Data Transfer Object:**
```php
// app/DTOs/PaymentResult.php
<?php

namespace App\DTOs;

readonly class PaymentResult
{
    public function __construct(
        public bool $success,
        public string $transactionId,
        public float $amount,
        public array $metadata = [],
        public ?string $errorMessage = null,
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
            'metadata' => $this->metadata,
            'error_message' => $this->errorMessage,
        ];
    }
}
```

---

## Decision Matrix: When to Create Each Component

### Form Requests
**Create when:**
- Any form submission
- Any API endpoint receiving input
- Need custom validation logic
- Need to authorize within validation

**Don't create when:**
- Simple GET requests with no input
- URL parameters only (use route constraints instead)

### API Resources
**Create when:**
- Building REST APIs
- Need to transform model attributes
- Need to hide sensitive data
- Want consistent API response format
- Need conditional field inclusion

**Don't create when:**
- Internal, non-API endpoints
- Simple CRUD returning models directly
- Admin panels where you control the frontend

### Policies
**Create when:**
- Resource-based authorization (CRUD operations)
- Complex permission logic
- Need to check ownership or roles
- Building APIs with authorization

**Don't create when:**
- Simple boolean checks (use Gates instead)
- Non-resource-based permissions

### Services
**Create when:**
- Multi-step business logic
- Orchestrating multiple models
- Complex calculations or operations
- External API integrations
- Need transaction management
- Business rules that span multiple controllers

**Don't create when:**
- Simple CRUD operations
- Single model manipulation
- No business logic (just validation + save)

### Contracts/Interfaces
**Create when:**
- Multiple implementations possible (payment gateways, notification channels)
- Need to swap implementations (dev vs production)
- Want to mock in tests
- Building a package or reusable module

**Don't create when:**
- Only one implementation will ever exist
- Simple internal services

### Repositories (Optional)
**Create when:**
- Complex queries reused across the app
- Need to swap data sources
- Prefer thin controllers
- Building large applications

**Don't create when:**
- Simple Eloquent queries
- Small to medium applications
- Queries are used in only one place

---

## Security Checklist Template

Use this for every new feature:

```markdown
## Security Review Checklist

### Input Validation
- [ ] Form Request created with comprehensive validation rules
- [ ] All input sanitized and validated
- [ ] File uploads validated (type, size, content)
- [ ] Mass assignment protected with $fillable or $guarded

### Authorization
- [ ] Policy created for resource
- [ ] authorize() called in all controller methods
- [ ] Route middleware applied correctly
- [ ] Gate checks for non-resource permissions

### Injection Prevention
- [ ] Using Eloquent ORM or Query Builder with bindings
- [ ] No raw SQL with string concatenation
- [ ] Blade escaping {{ }} used (not {!! !!})
- [ ] Rich text sanitized with HTML Purifier

### Authentication & Sessions
- [ ] Rate limiting implemented
- [ ] Strong password requirements
- [ ] Session configuration secure (secure, httponly, samesite)
- [ ] CSRF protection enabled

### Data Protection
- [ ] Sensitive data encrypted
- [ ] Passwords hashed with bcrypt/argon2
- [ ] API tokens secured
- [ ] HTTPS enforced in production

### Logging & Monitoring
- [ ] Audit logging for sensitive operations
- [ ] Failed authorization attempts logged
- [ ] Security events logged
- [ ] Error messages don't leak sensitive info

### API Security
- [ ] API Resource hides sensitive fields
- [ ] Rate limiting applied
- [ ] Authentication required
- [ ] CORS configured correctly

### Infrastructure
- [ ] Security headers middleware applied
- [ ] APP_DEBUG=false in production
- [ ] Dependencies updated (composer audit)
- [ ] .env file protected
```

---

## Common Security Mistakes to Avoid

### ❌ Don't Do This:
```php
// Raw SQL with concatenation
DB::select("SELECT * FROM users WHERE id = " . $id);

// Exposing sensitive data in API
return User::find($id); // Includes password hash!

// No authorization check
public function delete(Post $post) {
    $post->delete(); // Anyone can delete any post!
}

// Mass assignment vulnerability
User::create($request->all()); // No $fillable defined!

// Weak validation
'email' => 'required', // Missing email format validation

// Direct file path from user input
Storage::get($request->path); // Path traversal vulnerability!
```

### ✅ Do This Instead:
```php
// Parameterized query
DB::select("SELECT * FROM users WHERE id = ?", [$id]);

// Use API Resource
return new UserResource(User::find($id));

// Authorization check
public function delete(Post $post) {
    $this->authorize('delete', $post);
    $post->delete();
}

// Protected mass assignment
protected $fillable = ['name', 'email'];
User::create($request->validated());

// Proper validation
'email' => 'required|email|max:255',

// Validate and sanitize file paths
$path = $request->validate(['path' => 'required|string']);
$allowedPaths = ['uploads', 'public'];
// ... check if path is in allowed list
```

---

## Quick Commands Reference

```bash
# Generate components
php artisan make:policy PostPolicy --model=Post
php artisan make:request StorePostRequest
php artisan make:resource PostResource
php artisan make:middleware SecurityHeaders
php artisan make:service UserRegistrationService
php artisan make:observer PostObserver --model=Post

# Security checks
composer audit
php artisan route:list  # Review all routes
php artisan optimize:clear  # Clear caches before deployment

# Testing
php artisan test --filter SecurityTest
```

---

## Usage Examples

When implementing a new feature in Laravel, follow this pattern:

1. **Create Form Request** for validation
2. **Create Policy** for authorization
3. **Create API Resource** for responses (if API)
4. **Create Service** if complex business logic
5. **Implement Controller** that ties everything together
6. **Add Security Logging** for sensitive operations
7. **Run Security Checklist** before deployment

This skill should guide secure Laravel development with proper separation of concerns and defense-in-depth security practices.