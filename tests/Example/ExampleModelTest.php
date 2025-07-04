<?php

declare(strict_types=1);
describe('Example Application Models', function (): void {
    $examplePath = __DIR__ . '/../../example';

    it('User model has required traits and methods', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Models/User.php');
        
        expect($content)
            ->toContain('use HasFactory, Notifiable, HasAuthorization;')
            ->toContain('use OpenFGA\Laravel\Traits\HasAuthorization;')
            ->toContain('public function organizations()')
            ->toContain('public function teams()')
            ->toContain('public function ownedDocuments()')
            ->toContain('belongsToMany(Organization::class)')
            ->toContain('belongsToMany(Team::class)')
            ->toContain('hasMany(Document::class');
    });

    it('Organization model has required relationships', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Models/Organization.php');
        
        expect($content)
            ->toContain('use HasFactory, HasAuthorization;')
            ->toContain('public function departments()')
            ->toContain('public function users()')
            ->toContain('public function documents()')
            ->toContain('public function folders()')
            ->toContain('hasMany(Department::class)')
            ->toContain('belongsToMany(User::class)')
            ->toContain('hasMany(Folder::class)');
    });

    it('Department model has parent relationship', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Models/Department.php');
        
        expect($content)
            ->toContain('use HasFactory, HasAuthorization;')
            ->toContain('public function organization()')
            ->toContain('public function teams()')
            ->toContain('public function users()')
            ->toContain('belongsTo(Organization::class)')
            ->toContain('hasMany(Team::class)')
            ->toContain('belongsToMany(User::class)');
    });

    it('Team model has department relationship', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Models/Team.php');
        
        expect($content)
            ->toContain('use HasFactory, HasAuthorization;')
            ->toContain('public function department()')
            ->toContain('public function users()')
            ->toContain('public function documents()')
            ->toContain('public function folders()')
            ->toContain('belongsTo(Department::class)')
            ->toContain('belongsToMany(User::class)')
            ->toContain('morphMany(Document::class')
            ->toContain('morphMany(Folder::class');
    });

    it('Document model has authorization methods', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Models/Document.php');
        
        expect($content)
            ->toContain('use HasFactory, HasAuthorization')
            ->toContain('public function owner()')
            ->toContain('public function folder()')
            ->toContain('public function team()')
            ->toContain('public function shareWith(')
            ->toContain('public function removeAccess(')
            ->toContain('public function getAccessibleUsers(')
            ->toContain('belongsTo(User::class')
            ->toContain('belongsTo(Folder::class)')
            ->toContain('belongsTo(Team::class)');
    });

    it('Folder model has parent relationships', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Models/Folder.php');
        
        expect($content)
            ->toContain('use HasFactory, HasAuthorization;')
            ->toContain('public function organization()')
            ->toContain('public function department()')
            ->toContain('public function team()')
            ->toContain('public function parent()')
            ->toContain('public function children()')
            ->toContain('public function documents()')
            ->toContain('public function getFullPath()')
            ->toContain('hasMany(Folder::class')
            ->toContain('hasMany(Document::class)');
    });

    it('all models have fillable properties defined', function () use ($examplePath): void {
        $models = [
            'Organization',
            'Department',
            'Team',
            'Document',
            'Folder'
        ];
        
        foreach ($models as $model) {
            $content = file_get_contents($examplePath . "/app/Models/{$model}.php");
            
            expect($content)
                ->toContain('protected $fillable = [');
            
            if ($model === 'Document') {
                expect($content)
                    ->toContain("'title'")
                    ->toContain("'content'");
            } else {
                expect($content)
                    ->toContain("'name'");
            }
        }
    });

    it('models use proper authorization object naming', function () use ($examplePath): void {
        $models = [
            'Organization' => 'organization',
            'Department' => 'department', 
            'Team' => 'team',
            'Document' => 'document',
            'Folder' => 'folder'
        ];
        
        foreach ($models as $model => $object) {
            $content = file_get_contents($examplePath . "/app/Models/{$model}.php");
            
            expect($content)
                ->toContain("public function authorizationObject(): string")
                ->toContain("return '{$object}:' . \$this->id;");
        }
    });
});