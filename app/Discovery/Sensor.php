<?php
/**
 * Sensor.php
 *
 * Collects discovered sensors and allows the deletion of non-discovered sensors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2024 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Discovery;

use App\Models\Device;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\Config;
use LibreNMS\DB\SyncsModels;

class Sensor
{
    use SyncsModels;

    private Collection $models;
    /** @var bool[] */
    private array $discovered = [];
    private string $relationship = 'sensors';
    private Device $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->models = new Collection;
    }

    public function discover(\App\Models\Sensor $sensor): static
    {
        if ($this->canSkip($sensor)) {
            Log::info('~');
            Log::debug("Skipped Sensor: $sensor");

            return $this;
        }
        $sensor->device_id ??= \DeviceCache::getPrimary()->device_id;
        $this->models->push($sensor);
        $this->discovered[$sensor->syncGroup()] = false;

        Log::debug("Discovered Sensor: $sensor");
        Log::info("$sensor->sensor_descr: Cur $sensor->sensor_current, Low: $sensor->sensor_limit_low, Low Warn: $sensor->sensor_limit_low_warn, Warn: $sensor->sensor_limit_warn, High: $sensor->sensor_limit");

        return $this;
    }

    public function isDiscovered(string $type): bool
    {
        return $this->discovered[$type] ?? false;
    }

    public function sync(...$params): Collection
    {
        $type = implode('-', $params);

        if (! $this->isDiscovered($type)) {
            $synced = $this->syncModelsByGroup($this->device, 'sensors', $this->getModels(), $params);
            $this->discovered[$type] = true;

            return $synced;
        }

        return new Collection;
    }

    public function getModels(): Collection
    {
        return $this->models;
    }

    public function canSkip(\App\Models\Sensor $sensor): bool
    {
        if (! empty($sensor->sensor_class) && (Config::getOsSetting($this->device->os, "disabled_sensors.$sensor->sensor_class") || Config::get("disabled_sensors.$sensor->sensor_class"))) {
            return true;
        }
        foreach (Config::getCombined($this->device->os, 'disabled_sensors_regex') as $skipRegex) {
            if (! empty($sensor->sensor_descr) && preg_match($skipRegex, $sensor->sensor_descr)) {
                return true;
            }
        }
        foreach (Config::getCombined($this->device->os, "disabled_sensors_regex.$sensor->sensor_class") as $skipRegex) {
            if (! empty($sensor->sensor_descr) && preg_match($skipRegex, $sensor->sensor_descr)) {
                return true;
            }
        }

        return false;
    }
}
