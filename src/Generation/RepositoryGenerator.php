<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Generation;

use ByfallCode\ByfallCrud\Generation\Contracts\Generator;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;

final class RepositoryGenerator implements Generator
{
    public function generate(EntityMetadata $metadata, GenerationContext $context): string
    {
        $name = $context->entityName;
        $modelUse = "use App\\Models\\{$name};";
        $queryApplierUse = "use App\\Queries\\{$name}QueryApplier;";
        $querySpecificationUse = "use App\\Queries\\{$name}QuerySpecification;";
        return <<<PHP
<?php
declare(strict_types=1);

namespace App\Repositories;

{$modelUse}
{$queryApplierUse}
{$querySpecificationUse}
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class {$name}Repository
{
    public function paginate({$name}QuerySpecification \$specification): LengthAwarePaginator
    {
        \$query = (new {$name}QueryApplier())->apply({$name}::query(), \$specification);
        return \$query->paginate(\$specification->perPage)->appends(\$specification->queryParameters());
    }

    /** @return Collection<int, {$name}> */
    public function all(): Collection { return {$name}::query()->get(); }
    public function find(int|string \$id): {$name} { return {$name}::query()->findOrFail(\$id); }
    public function create(array \$data): {$name} { return {$name}::query()->create(\$data); }
    public function update(int|string \$id, array \$data): {$name}
    {
        \$item = \$this->find(\$id); \$item->update(\$data); return \$item;
    }
    public function delete(int|string \$id): void { \$this->find(\$id)->delete(); }
}
PHP;
    }
}
