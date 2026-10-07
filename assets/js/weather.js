/* =========================================================
   Previsão do tempo (Open-Meteo: gratuito, sem chave de API)
   A cidade escolhida fica guardada no navegador.
   ========================================================= */
(() => {
  const main = $('#weather-city');
  if (!main) return;

  const KEY = 'gf-cidade';
  const DEFAULT = { name: 'Lisboa', country: 'Portugal', lat: 38.7167, lon: -9.1333 };
  const codes = {
    0: ['☀️', 'Céu limpo'], 1: ['🌤️', 'Pouco nublado'], 2: ['⛅', 'Parcialmente nublado'], 3: ['☁️', 'Nublado'],
    45: ['🌫️', 'Nevoeiro'], 48: ['🌫️', 'Nevoeiro com geada'],
    51: ['🌦️', 'Chuvisco fraco'], 53: ['🌦️', 'Chuvisco'], 55: ['🌦️', 'Chuvisco forte'],
    56: ['🌧️', 'Chuvisco gelado'], 57: ['🌧️', 'Chuvisco gelado forte'],
    61: ['🌧️', 'Chuva fraca'], 63: ['🌧️', 'Chuva'], 65: ['🌧️', 'Chuva forte'],
    66: ['🌧️', 'Chuva gelada'], 67: ['🌧️', 'Chuva gelada forte'],
    71: ['🌨️', 'Neve fraca'], 73: ['🌨️', 'Neve'], 75: ['❄️', 'Neve forte'], 77: ['🌨️', 'Grãos de neve'],
    80: ['🌦️', 'Aguaceiros fracos'], 81: ['🌧️', 'Aguaceiros'], 82: ['⛈️', 'Aguaceiros fortes'],
    85: ['🌨️', 'Aguaceiros de neve'], 86: ['❄️', 'Aguaceiros de neve fortes'],
    95: ['⛈️', 'Trovoada'], 96: ['⛈️', 'Trovoada com granizo'], 99: ['⛈️', 'Trovoada forte com granizo']
  };
  const info = c => codes[c] || ['🌡️', 'Tempo variável'];
  const chip = $('#weather-chip');
  let loadedFor = null;

  function getCity() {
    try { return JSON.parse(localStorage.getItem(KEY)) || DEFAULT; } catch { return DEFAULT; }
  }
  function setCity(c) {
    try { localStorage.setItem(KEY, JSON.stringify(c)); } catch {}
  }

  function tip(code, temp, rainProb) {
    if (code >= 95) return '⛈️ Trovoada prevista: confirma entregas e deslocações e protege o material.';
    if ((code >= 61 && code <= 67) || (code >= 80 && code <= 82) || rainProb >= 70) return '☔ Dia de chuva: bom momento para vendas online, entregas ao domicílio e tarefas administrativas.';
    if (code >= 71 && code <= 86) return '❄️ Neve ou frio intenso: atenção às deslocações e a possíveis atrasos de fornecedores.';
    if (temp >= 30) return '🥵 Muito calor: reforça bebidas frescas e evita entregas nas horas de maior calor.';
    if (temp <= 5) return '🧣 Está frio: produtos e serviços de inverno costumam ter mais procura.';
    if (code <= 1) return '😎 Bom tempo: ótimo dia para atendimento presencial, montras e eventos ao ar livre.';
    return '📋 Tempo estável: aproveita para organizar o caixa e rever as contas da semana.';
  }

  async function load(city, force = false) {
    const id = `${city.lat},${city.lon}`;
    if (!force && loadedFor === id) return;
    main.textContent = 'A carregar…';
    try {
      const url = `https://api.open-meteo.com/v1/forecast?latitude=${city.lat}&longitude=${city.lon}`
        + '&current=temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m'
        + '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=auto&forecast_days=6';
      const r = await fetch(url);
      if (!r.ok) throw Error();
      const d = await r.json();
      const c = d.current, [icon, desc] = info(c.weather_code);
      loadedFor = id;
      main.textContent = city.country ? `${city.name}, ${city.country}` : city.name;
      $('#weather-icon').textContent = icon;
      $('#weather-temp').textContent = `${Math.round(c.temperature_2m)}°`;
      $('#weather-desc').textContent = desc;
      $('#weather-feels').textContent = `${Math.round(c.apparent_temperature)}°`;
      $('#weather-humidity').textContent = `${c.relative_humidity_2m}%`;
      $('#weather-wind').textContent = `${Math.round(c.wind_speed_10m)} km/h`;
      $('#weather-rain').textContent = `${c.precipitation} mm`;
      $('#weather-tip').textContent = tip(c.weather_code, c.temperature_2m, d.daily.precipitation_probability_max?.[0] ?? 0);
      $('#forecast').innerHTML = d.daily.time.slice(1).map((day, i) => {
        const k = i + 1, [ic, ds] = info(d.daily.weather_code[k]);
        const [y, m, dd] = day.split('-').map(Number);
        const name = new Date(y, m - 1, dd).toLocaleDateString((window.LUMINA_LOCALE || 'pt-PT'), { weekday: 'short' }).replace('.', '');
        return `<div class="forecast-day" title="${esc(ds)}"><span>${esc(name)}</span><b aria-hidden="true">${ic}</b>
          <strong>${Math.round(d.daily.temperature_2m_max[k])}°</strong><small>${Math.round(d.daily.temperature_2m_min[k])}°</small>
          <em>💧 ${d.daily.precipitation_probability_max?.[k] ?? 0}%</em></div>`;
      }).join('');
      chip.textContent = `${icon} ${Math.round(c.temperature_2m)}° ${city.name}`;
      chip.hidden = false;
    } catch {
      main.textContent = 'Sem ligação à previsão';
      $('#weather-desc').textContent = 'Verifica a ligação à internet. O resto do painel funciona normalmente.';
      $('#weather-tip').textContent = '';
      $('#forecast').innerHTML = '';
      chip.hidden = true;
    }
  }

  const results = $('#weather-results');
  $('#weather-form').addEventListener('submit', async e => {
    e.preventDefault();
    const q = e.target.elements.city.value.trim();
    if (!q) return;
    results.innerHTML = '<p class="muted">A pesquisar…</p>';
    try {
      const r = await fetch(`https://geocoding-api.open-meteo.com/v1/search?name=${encodeURIComponent(q)}&count=6&language=pt&format=json`);
      const d = await r.json();
      const list = d.results || [];
      results.innerHTML = list.length ? list.map((c, i) => `<button type="button" class="weather-result" data-i="${i}">
          <b>${esc(c.name)}</b><small>${esc([c.admin1, c.country].filter(Boolean).join(', '))}</small></button>`).join('')
        : '<p class="muted">Nenhuma cidade encontrada.</p>';
      results.querySelectorAll('[data-i]').forEach(b => b.onclick = () => {
        const c = list[Number(b.dataset.i)];
        const city = { name: c.name, country: c.country || '', lat: c.latitude, lon: c.longitude };
        setCity(city); results.innerHTML = ''; e.target.reset(); load(city, true);
      });
    } catch { results.innerHTML = '<p class="muted">Não foi possível pesquisar. Verifica a ligação à internet.</p>'; }
  });

  $('#weather-geo').onclick = () => {
    if (!navigator.geolocation) return results.innerHTML = '<p class="muted">O navegador não suporta localização.</p>';
    results.innerHTML = '<p class="muted">A obter a localização…</p>';
    navigator.geolocation.getCurrentPosition(pos => {
      const city = { name: 'A minha localização', country: '', lat: +pos.coords.latitude.toFixed(4), lon: +pos.coords.longitude.toFixed(4) };
      setCity(city); results.innerHTML = ''; load(city, true);
    }, () => { results.innerHTML = '<p class="muted">Não foi possível obter a localização (permissão recusada?).</p>'; }, { timeout: 10000 });
  };

  document.addEventListener('gf:section', e => { if (e.detail === 'weather') load(getCity()); });
  load(getCity());   // também preenche o chip ao lado de "Novo movimento"
})();
