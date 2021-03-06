<?php

namespace Koodilab\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Koodilab\Contracts\Models\Behaviors\Positionable as PositionableContract;
use Koodilab\Events\PlanetUpdated;
use Koodilab\Support\StateManager;

/**
 * Planet.
 *
 * @property int $id
 * @property int $resource_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $custom_name
 * @property int $x
 * @property int $y
 * @property int $size
 * @property int|null $capacity
 * @property int|null $supply
 * @property int|null $mining_rate
 * @property int|null $production_rate
 * @property float|null $defense_bonus
 * @property float|null $construction_time_bonus
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Illuminate\Database\Eloquent\Collection|Construction[] $constructions
 * @property string $display_name
 * @property int $free_capacity
 * @property int $free_supply
 * @property int $resource_count
 * @property int $used_capacity
 * @property int $used_supply
 * @property int $used_training_supply
 * @property \Illuminate\Database\Eloquent\Collection|Grid[] $grids
 * @property \Illuminate\Database\Eloquent\Collection|Movement[] $incomingMovements
 * @property \Illuminate\Database\Eloquent\Collection|Mission[] $missions
 * @property \Illuminate\Database\Eloquent\Collection|Movement[] $outgoingMovements
 * @property \Illuminate\Database\Eloquent\Collection|Population[] $populations
 * @property resource $resource
 * @property \Illuminate\Database\Eloquent\Collection|Stock[] $stocks
 * @property \Illuminate\Database\Eloquent\Collection|Training[] $trainings
 * @property \Illuminate\Database\Eloquent\Collection|Upgrade[] $upgrades
 * @property User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Planet inBounds(\Koodilab\Support\Bounds $bounds)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet starter()
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereConstructionTimeBonus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereCustomName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereDefenseBonus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereMiningRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereProductionRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereSupply($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereX($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Planet whereY($value)
 * @mixin \Eloquent
 */
class Planet extends Model implements PositionableContract
{
    use Behaviors\Positionable,
        Concerns\HasCapacity,
        Concerns\HasCustomName,
        Concerns\HasSupply,
        Queries\FindBuildings,
        Queries\FindFreeCapital,
        Queries\FindGrids,
        Queries\FindIncomingMovements,
        Queries\FindNotExpiredMissions,
        Queries\FindNotEmptyGrids,
        Queries\FindOutgoingMovements,
        Queries\FindPopulationByUnit,
        Queries\FindPopulationsByUnitIds,
        Queries\FindStock,
        Queries\FindStocksByResourceIds,
        Queries\IncomingMovementCount,
        Queries\IncomingAttackMovementCount,
        Queries\OutgoingAttackMovementCount,
        Relations\BelongsToResource,
        Relations\BelongsToUser,
        Relations\HasManyStock,
        Relations\HasManyPopulation,
        Relations\HasManyGrid,
        Relations\HasManyMission;

    /**
     * The small size.
     *
     * @var int
     */
    const SIZE_SMALL = 0;

    /**
     * The medium size.
     *
     * @var int
     */
    const SIZE_MEDIUM = 1;

    /**
     * The large size.
     *
     * @var int
     */
    const SIZE_LARGE = 2;

    /**
     * The resource count.
     *
     * @var int
     */
    const RESOURCE_COUNT = 3;

    /**
     * The settler count.
     *
     * @var int
     */
    const SETTLER_COUNT = 1;

    /**
     * The capital step.
     *
     * @var int
     */
    const CAPITAL_STEP = 1024;

    /**
     * {@inheritdoc}
     */
    protected $perPage = 30;

    /**
     * {@inheritdoc}
     */
    protected $guarded = [
        'id', 'created_at', 'updated_at',
    ];

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        static::updating(function (self $planet) {
            if ($planet->isDirty('user_id')) {
                $userId = $planet->getOriginal('user_id');

                if ($userId) {
                    $user = User::find($userId);

                    $planet->custom_name = null;
                    $planet->capacity = null;
                    $planet->supply = null;
                    $planet->mining_rate = null;
                    $planet->production_rate = null;
                    $planet->defense_bonus = null;
                    $planet->construction_time_bonus = null;
                    $planet->incomingMovements()->where('user_id', $user->id)->delete();
                    $planet->outgoingMovements()->where('user_id', $user->id)->delete();
                    $planet->constructions()->delete();
                    $planet->upgrades()->delete();
                    $planet->trainings()->delete();
                    $planet->missions()->delete();

                    $planet->grids()->update([
                        'level' => null,
                        'building_id' => null,
                    ]);

                    if ($planet->id == $user->current_id) {
                        $user->update([
                            'current_id' => $user->capital_id,
                        ]);
                    }

                    app(StateManager::class)->syncUser($user);
                }
            }

            if ($planet->user_id) {
                app(StateManager::class)->syncUser($planet->user);
            }
        });

        static::updated(function (self $planet) {
            event(
                new PlanetUpdated($planet->id)
            );
        });
    }

    /**
     * Get the incoming movements.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function incomingMovements()
    {
        return $this->hasMany(Movement::class, 'end_id');
    }

    /**
     * Get the outgoing movements.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function outgoingMovements()
    {
        return $this->hasMany(Movement::class, 'start_id');
    }

    /**
     * Get the constructions.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function constructions()
    {
        return $this->hasManyThrough(Construction::class, Grid::class);
    }

    /**
     * Get the upgrades.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function upgrades()
    {
        return $this->hasManyThrough(Upgrade::class, Grid::class);
    }

    /**
     * Get the trainings.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function trainings()
    {
        return $this->hasManyThrough(Training::class, Grid::class);
    }

    /**
     * Is capital?
     *
     * @return bool
     */
    public function isCapital()
    {
        return $this->user_id && $this->id == $this->user->capital_id;
    }

    /**
     * Get the resource count attribute.
     *
     * @return int
     */
    public function getResourceCountAttribute()
    {
        return static::RESOURCE_COUNT + $this->size;
    }

    /**
     * Create or update stock.
     */
    public function createOrUpdateStock()
    {
        /** @var Stock $stock */
        $stock = $this->stocks()->firstOrNew([
            'resource_id' => $this->resource_id,
        ]);

        $stock->setRelation('planet', $this)->fill([
            'quantity' => $stock->quantity,
            'last_quantity_changed' => Carbon::now(),
        ])->save();
    }

    /**
     * Create or update population.
     *
     * @param Unit $unit
     * @param int  $quantity
     */
    public function createOrUpdatePopulation(Unit $unit, $quantity)
    {
        /** @var Population $population */
        $population = $this->populations()->firstOrNew([
            'unit_id' => $unit->id,
        ]);

        $population->setRelations([
            'planet' => $this,
            'unit' => $unit,
        ])->incrementQuantity($quantity);
    }

    /**
     * Starter scope.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeStarter(Builder $query)
    {
        $resourceId = Resource::where('is_unlocked', true)
            ->orderBy('sort_order')
            ->value('id');

        return $query
            ->whereNull('user_id')
            ->where('resource_id', $resourceId)
            ->where('size', static::SIZE_SMALL);
    }
}
