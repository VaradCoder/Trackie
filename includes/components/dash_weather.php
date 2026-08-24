<?php
/**
 * Dashboard — Weather card (column 2, top)
 * Expects: $weatherData (array|null)
 */
?>
<div class="d-card d-card-muted" aria-label="Weather widget">
  <div class="d-card-header">
    <span class="d-card-title">Weather</span>
    <?php if ($weatherData): ?>
      <span class="d-card-link">
        <?= h($weatherData['city']) ?>, <?= h($weatherData['country']) ?>
      </span>
    <?php endif; ?>
  </div>

  <div class="d-card-body">
    <?php if ($weatherData): ?>

      <!-- Temperature hero -->
      <div class="weather-hero">
        <div class="weather-icon-wrap" aria-hidden="true">
          <i class="fas <?= h($weatherData['icon']) ?>" style="font-size:2.25rem;color:var(--accent)"></i>
        </div>
        <div>
          <div class="weather-city"><?= h($weatherData['city']) ?>, <?= h($weatherData['country']) ?></div>
          <div class="weather-temp-val" aria-label="Temperature <?= $weatherData['temp'] ?> degrees Celsius">
            <?= $weatherData['temp'] ?><span class="weather-temp-unit">°C</span>
          </div>
          <div class="weather-desc"><?= h($weatherData['desc']) ?></div>
        </div>
      </div>

      <!-- Stat row -->
      <div class="weather-stats" role="list">
        <div class="weather-stat" role="listitem">
          <div class="weather-stat-label">Wind</div>
          <div class="weather-stat-value"><?= $weatherData['wind'] ?> m/s</div>
        </div>
        <div class="weather-stat" role="listitem">
          <div class="weather-stat-label">Pressure</div>
          <div class="weather-stat-value"><?= $weatherData['pressure'] ?> hPa</div>
        </div>
        <div class="weather-stat" role="listitem">
          <div class="weather-stat-label">Humidity</div>
          <div class="weather-stat-value"><?= $weatherData['humidity'] ?>%</div>
        </div>
      </div>

      <!-- Required attribution — Open-Meteo data is CC BY 4.0 -->
      <div style="text-align:right;margin-top:.5rem">
        <a href="https://open-meteo.com/" target="_blank" rel="noopener" style="font-size:.6875rem;color:var(--subtle);text-decoration:none">
          Weather by Open-Meteo.com
        </a>
      </div>

    <?php else: ?>

      <!-- Graceful fallback — no fake data -->
      <div class="weather-fallback" role="status" aria-label="Weather unavailable">
        <div class="weather-fallback-icon" aria-hidden="true">
          <i class="fas fa-cloud-sun"></i>
        </div>
        <div class="weather-fallback-title">Weather unavailable</div>
        <div class="weather-fallback-hint">
          Live weather will appear here once it's available.
        </div>
      </div>

    <?php endif; ?>
  </div>
</div>
