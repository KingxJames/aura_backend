<?php

namespace App\Providers;

use App\Models\AuralAttempt;
use App\Models\AuralModuleAttempt;
use App\Models\TutorConversation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short, stable aliases for ExerciseFeedback's polymorphic relation -
        // never expose raw App\Models\* class names over the API wire.
        // Non-enforcing: Sanctum's own tokenable morph relation on User (used
        // on every authenticated request) isn't in this map, and enforceMorphMap()
        // would reject it - morphMap() lets anything not listed here fall back
        // to its normal fully-qualified class name instead of throwing.
        Relation::morphMap([
            'aural_attempt' => AuralAttempt::class,
            'module_attempt' => AuralModuleAttempt::class,
            'tutor_message' => TutorConversation::class,
        ]);
    }
}
