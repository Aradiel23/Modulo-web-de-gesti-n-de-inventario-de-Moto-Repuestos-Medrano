/* Archivo de interfaz; no modifica el inventario.json directamente.*/
document.documentElement.classList.add("js");

(() => {
  'use strict';
  const normalizar = (texto) => String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

  // 1) Tablas con cinco registros por página y un máximo de cinco números visibles.
  document.querySelectorAll('table.js-paginated-table').forEach((tabla) => {
    const filas = Array.from(tabla.querySelectorAll('tbody > tr'));
    const tamano = 5;
    const totalPaginas = Math.ceil(filas.length / tamano);
    let pagina = 1;
    tabla.classList.add('pagination-ready');
    if (totalPaginas <= 1) return;

    const paginador = document.createElement('nav');
    paginador.className = 'table-pager';
    paginador.setAttribute('aria-label', 'Páginas de ' + (tabla.dataset.pagingName || 'la tabla'));
    const botones = document.createElement('div');
    botones.className = 'table-pager-buttons';
    const descripcion = document.createElement('small');
    descripcion.className = 'table-pager-description';
    descripcion.setAttribute('aria-live', 'polite');
    paginador.append(botones, descripcion);
    tabla.closest('.table-wrap').insertAdjacentElement('afterend', paginador);

    function boton(texto, destino, etiqueta, activo = false) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = texto;
      b.className = 'table-pager-btn' + (activo ? ' is-current' : '');
      b.setAttribute('aria-label', etiqueta);
      if (activo) b.setAttribute('aria-current', 'page');
      b.disabled = destino < 1 || destino > totalPaginas;
      b.addEventListener('click', () => {
        pagina = destino;
        mostrar();
        const panel = tabla.closest('.panel');
        const rect = panel.getBoundingClientRect();
        if (rect.top < 0 || rect.top > window.innerHeight - 120) {
          panel.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
        }
      });
      return b;
    }

    function mostrar() {
      const inicio = (pagina - 1) * tamano;
      const fin = Math.min(inicio + tamano, filas.length);
      filas.forEach((fila, i) => {
        fila.hidden = i < inicio || i >= fin;
        fila.classList.remove('row-fade-in');
        if (!fila.hidden) {
          void fila.offsetWidth;
          fila.style.animationDelay = ((i - inicio) * 35) + 'ms';
          fila.classList.add('row-fade-in');
        }
      });
      botones.replaceChildren();
      botones.append(boton('«', pagina - 1, 'Página anterior'));


      // Ventana deslizante de cinco números, como en el ejemplo proporcionado.
      let desde = Math.max(1, pagina - 2);
      let hasta = Math.min(totalPaginas, desde + 4);
      desde = Math.max(1, hasta - 4);
      for (let n = desde; n <= hasta; n++) {
        botones.append(boton(String(n), n, 'Ir a la página ' + n, n === pagina));
      }
      botones.append(boton('»', pagina + 1, 'Página siguiente'));
      descripcion.textContent = `Mostrando ${inicio + 1}–${fin} de ${filas.length} ${tabla.dataset.pagingName || 'registros'}`;
    }
    mostrar();
  });


  // 2) Buscadores múltiples. Al filtrar, NO se borran las selecciones existentes.
  document.querySelectorAll('[data-searchable-checklist]').forEach((grupo) => {
    const buscador = grupo.querySelector('[data-list-filter]');
    const lista = grupo.querySelector('[data-choice-list]');
    const etiquetas = Array.from(lista.querySelectorAll('label.check-item'));
    const seleccionados = grupo.querySelector('[data-selected-chips]');
    const contador = grupo.querySelector('[data-filter-count]');

    function actualizarEtiquetas() {
      seleccionados.replaceChildren();
      const marcados = etiquetas.filter((etiqueta) => etiqueta.querySelector('input').checked);
      marcados.forEach((etiqueta) => {
        const casilla = etiqueta.querySelector('input');
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'selected-chip';
        chip.textContent = etiqueta.textContent.trim() + ' ×';
        chip.title = 'Quitar selección';
        chip.addEventListener('click', () => {
          casilla.checked = false;
          actualizarEtiquetas();
        });
        seleccionados.append(chip);
      });
    }
    function filtrar() {
      const consulta = normalizar(buscador.value);
      let visibles = 0;
      etiquetas.forEach((etiqueta) => {
        const coincide = normalizar(etiqueta.textContent).includes(consulta);
        etiqueta.classList.toggle('is-filtered', !coincide);
        if (coincide) visibles++;
      });
      contador.textContent = `${visibles} resultado(s) · ${etiquetas.filter((l) => l.querySelector('input').checked).length} seleccionado(s)`;
    }
    buscador.addEventListener('input', filtrar);
    lista.addEventListener('change', () => { actualizarEtiquetas(); filtrar(); });
    actualizarEtiquetas();
    filtrar();
  });

  
  // 3) Campo de repuesto: escribe y selecciona una sugerencia; envía el SKU original al PHP.
  document.querySelectorAll('[data-product-combo]').forEach((grupo) => {
    const select = grupo.querySelector('select[name="sku"]');
    const entrada = grupo.querySelector('.combo-input');
    const sugerencias = grupo.querySelector('.combo-suggestions');
    const nota = grupo.querySelector('[data-combo-hint]');
    const productos = Array.from(select.options).filter(o => o.value).map(o => ({sku:o.value, texto:o.textContent.trim()}));
    let resultados = [];
    let indice = -1;
    grupo.classList.add('combo-enhanced');
    select.required = false;  // El campo visible valida la elección.
    entrada.required = true;

    function abrir() {
      sugerencias.hidden = false;
      entrada.setAttribute('aria-expanded', 'true');
    }
    function cerrar() {
      sugerencias.hidden = true;
      entrada.setAttribute('aria-expanded', 'false');
      indice = -1;
    }
    function elegir(producto) {
      select.value = producto.sku;
      entrada.value = producto.texto;
      entrada.setCustomValidity('');
      nota.textContent = 'Seleccionado: ' + producto.sku;
      cerrar();
    }
    function pintar() {
      const consulta = normalizar(entrada.value);
      resultados = productos.filter(p => normalizar(p.texto).includes(consulta));
      indice = -1;
      sugerencias.replaceChildren();
      resultados.slice(0, 8).forEach((producto, i) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'combo-option';
        b.setAttribute('role', 'option');
        b.textContent = producto.texto;
        b.addEventListener('mousedown', (ev) => ev.preventDefault());
        b.addEventListener('click', () => elegir(producto));
        sugerencias.append(b);
      });
      if (!resultados.length) {
        const aviso = document.createElement('div');
        aviso.className = 'combo-no-results';
        aviso.textContent = 'No hay repuestos que coincidan.';
        sugerencias.append(aviso);
      } else if (resultados.length > 8) {
        const aviso = document.createElement('div');
        aviso.className = 'combo-no-results';
        aviso.textContent = `${resultados.length} coincidencias. Escribe más para afinar la búsqueda.`;
        sugerencias.append(aviso);
      }
      abrir();
      nota.textContent = `${resultados.length} repuesto(s) encontrado(s). Selecciona una sugerencia.`;
    }
    function resaltar() {
      sugerencias.querySelectorAll('.combo-option').forEach((b, i) => b.classList.toggle('is-highlighted', i === indice));
    }
    entrada.addEventListener('input', () => {
      select.value = '';
      entrada.setCustomValidity('Selecciona un repuesto de la lista.');
      pintar();
    });
    entrada.addEventListener('focus', pintar);
    entrada.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') { cerrar(); return; }
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (sugerencias.hidden) pintar();
        const max = Math.min(8, resultados.length);
        if (!max) return;
        indice = ev.key === 'ArrowDown' ? (indice + 1) % max : (indice - 1 + max) % max;
        resaltar();
      } else if (ev.key === 'Enter' && !sugerencias.hidden && resultados.length && indice >= 0) {
        ev.preventDefault();
        elegir(resultados[indice]);
      }
    });
    document.addEventListener('click', (ev) => { if (!grupo.contains(ev.target)) cerrar(); });
    grupo.closest('form').addEventListener('submit', (ev) => {
      if (!select.value) {
        ev.preventDefault();
        entrada.setCustomValidity('Selecciona un repuesto de la lista.');
        entrada.reportValidity();
        entrada.focus();
      }
    });
  });
})();
