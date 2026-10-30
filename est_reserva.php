<?php
/*
 * Sistema de cotización de reservas — Complejo Deportivo La Cantera
 * Nombre completo del estudiante: Paul Anderson Solano Torres
 * Fecha de elaboración: 29/10/2026
 * Archivo: est_reserva.php
 */

declare(strict_types=1);
date_default_timezone_set('America/Lima');

define('IGV', 0.18);
define('RECARGO_FIN_SEMANA', 0.15);
define('DESCUENTO_SOCIO', 0.20);
define('HORA_CIERRE', 23);

$tarifas = [
    'futbol7' => ['nombre' => 'Fútbol 7', 'diurna' => 80.00, 'nocturna' => 110.00],
    'fulbito' => ['nombre' => 'Fulbito (losa)', 'diurna' => 50.00, 'nocturna' => 70.00],
    'voley'   => ['nombre' => 'Vóley', 'diurna' => 40.00, 'nocturna' => 55.00],
    'padel'   => ['nombre' => 'Pádel', 'diurna' => 60.00, 'nocturna' => 85.00],
];

$preciosImplementos = [
    'chalecos' => ['nombre' => 'Chalecos (juego)', 'precio' => 12.00],
    'pelota'   => ['nombre' => 'Pelota', 'precio' => 8.00],
    'raquetas' => ['nombre' => 'Raquetas (par)', 'precio' => 15.00],
];

function h(mixed $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function obtenerFranja(int $hora): string
{
    return $hora < 18 ? 'diurna' : 'nocturna';
}

function nombreDia(string $fecha): string
{
    $numeroDia = (int)date('w', strtotime($fecha));
    switch ($numeroDia) {
        case 0: return 'domingo';
        case 1: return 'lunes';
        case 2: return 'martes';
        case 3: return 'miércoles';
        case 4: return 'jueves';
        case 5: return 'viernes';
        case 6: return 'sábado';
        default: return 'día no identificado';
    }
}

function esFinDeSemana(string $fecha): bool
{
    $numeroDia = (int)date('N', strtotime($fecha));
    return $numeroDia === 6 || $numeroDia === 7;
}

function calcularCostoHora(array $tarifas, string $cancha, int $hora, string $fecha): float
{
    $franja = obtenerFranja($hora);
    $tarifaBase = (float)$tarifas[$cancha][$franja];
    $factor = esFinDeSemana($fecha) ? (1 + RECARGO_FIN_SEMANA) : 1;
    return round($tarifaBase * $factor, 2);
}

function validarCodigoSocio(string $codigo): bool
{
    return preg_match('/^SOC-\d{4}$/', $codigo) === 1;
}

function formatearNombre(string $nombre): string
{
    $nombre = trim((string)(preg_replace('/\s+/u', ' ', $nombre) ?? $nombre));
    if (function_exists('mb_convert_case')) {
        return mb_convert_case(mb_strtolower($nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords(strtolower($nombre));
}

function generarCodigoReserva(string $cancha, string $fecha, int $hora, string $cliente, string $prefijo = 'LC'): string
{
    $textoCancha = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cancha);
    $textoCancha = strtoupper(preg_replace('/[^A-Za-z]/', '', $textoCancha ?: $cancha) ?? $cancha);
    $abreviaturaCancha = substr($textoCancha, 0, 3);

    $palabras = preg_split('/\s+/u', trim($cliente), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $iniciales = '';
    foreach ($palabras as $palabra) {
        if (function_exists('mb_substr')) {
            $iniciales .= mb_strtoupper(mb_substr($palabra, 0, 1, 'UTF-8'), 'UTF-8');
        } else {
            $iniciales .= strtoupper(substr($palabra, 0, 1));
        }
    }
    $fechaCodigo = date('dmY', strtotime($fecha));
    $horaCodigo = str_pad((string)$hora, 2, '0', STR_PAD_LEFT);

    // Se concatena LC + abreviatura para respetar el ejemplo del enunciado: LCPAD-21112026-17-MFQ.
    return $prefijo . $abreviaturaCancha . '-' . $fechaCodigo . '-' . $horaCodigo . '-' . $iniciales;
}

function agregarObservacion(array &$observaciones, string $mensaje): void
{
    $observaciones[] = $mensaje;
}

function formatearMonto(float $monto): string
{
    return 'S/ ' . number_format($monto, 2, '.', ',');
}

function fechaValida(string $fecha): bool
{
    $objetoFecha = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $objetoFecha instanceof DateTime && $objetoFecha->format('Y-m-d') === $fecha;
}

$errores = [];
$observaciones = [];
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombreOriginal = trim((string)($_POST['cliente'] ?? ''));
    $codigoSocio = trim((string)($_POST['codigo_socio'] ?? ''));
    $cancha = (string)($_POST['cancha'] ?? '');
    $fecha = (string)($_POST['fecha'] ?? '');
    $horaRaw = $_POST['hora_inicio'] ?? '';
    $cantidadRaw = $_POST['cantidad_horas'] ?? '';
    $implementosSeleccionados = $_POST['implementos'] ?? [];

    if ($nombreOriginal === '') {
        $errores[] = 'El nombre completo es obligatorio.';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($nombreOriginal, 'UTF-8') : strlen($nombreOriginal)) < 5) {
        $errores[] = 'El nombre completo debe tener al menos 5 caracteres.';
    }

    if (!array_key_exists($cancha, $tarifas)) {
        $errores[] = 'Selecciona un tipo de cancha válido.';
    }

    if ($fecha === '' || !fechaValida($fecha)) {
        $errores[] = 'Ingresa una fecha de reserva válida.';
    }

    $horaInicio = filter_var($horaRaw, FILTER_VALIDATE_INT);
    if ($horaInicio === false || $horaInicio < 6 || $horaInicio > 22) {
        $errores[] = 'La hora de inicio debe ser un número entero entre 6 y 22.';
    }

    $cantidadHoras = filter_var($cantidadRaw, FILTER_VALIDATE_INT);
    if ($cantidadHoras === false || $cantidadHoras < 1 || $cantidadHoras > 4) {
        $errores[] = 'La cantidad de horas debe ser un número entero entre 1 y 4.';
    }

    if ($horaInicio !== false && $cantidadHoras !== false && $horaInicio >= 6 && $horaInicio <= 22 && $cantidadHoras >= 1 && $cantidadHoras <= 4) {
        if (($horaInicio + $cantidadHoras) > HORA_CIERRE) {
            $errores[] = 'La reserva termina a las ' . str_pad((string)($horaInicio + $cantidadHoras), 2, '0', STR_PAD_LEFT) . ':00 y supera la hora de cierre (23:00). Ajusta el horario.';
        }
    }

    if (!is_array($implementosSeleccionados)) {
        $errores[] = 'La selección de implementos no tiene un formato válido.';
        $implementosSeleccionados = [];
    } else {
        foreach ($implementosSeleccionados as $implemento) {
            if (!is_string($implemento) || !array_key_exists($implemento, $preciosImplementos)) {
                $errores[] = 'Se recibió un implemento no reconocido. Vuelve al formulario y selecciona una opción válida.';
                break;
            }
        }
    }

    if ($errores === []) {
        $cliente = formatearNombre($nombreOriginal);
        $codigoSocioValido = ($codigoSocio !== '' && validarCodigoSocio($codigoSocio));

        if ($codigoSocio !== '' && !$codigoSocioValido) {
            agregarObservacion($observaciones, 'El código de socio ingresado no cumple el formato SOC- seguido de cuatro dígitos. La cotización se realizó como público general.');
        }

        $detalleHoras = [];
        $subtotalCancha = 0.00;
        $esFinDeSemana = esFinDeSemana($fecha);

        for ($i = 0; $i < $cantidadHoras; $i++) {
            $horaActual = $horaInicio + $i;
            $franja = obtenerFranja($horaActual);
            $tarifaBase = (float)$tarifas[$cancha][$franja];
            $recargo = $esFinDeSemana ? round($tarifaBase * RECARGO_FIN_SEMANA, 2) : 0.00;
            $costoHora = calcularCostoHora($tarifas, $cancha, $horaActual, $fecha);

            $detalleHoras[] = [
                'hora_inicio' => $horaActual,
                'horario' => str_pad((string)$horaActual, 2, '0', STR_PAD_LEFT) . ':00–' . str_pad((string)($horaActual + 1), 2, '0', STR_PAD_LEFT) . ':00',
                'franja' => $franja,
                'tarifa_base' => $tarifaBase,
                'recargo' => $recargo,
                'costo' => $costoHora,
                'gratis' => false,
            ];
            $subtotalCancha = round($subtotalCancha + $costoHora, 2);
        }

        $montoPromocion = 0.00;
        $indiceHoraGratis = null;
        if ($cantidadHoras >= 3) {
            // Búsqueda manual del menor costo. Al usar < y no <=, se conserva la primera hora en caso de empate.
            $indiceHoraMenor = 0;
            $costoMenor = $detalleHoras[0]['costo'];
            for ($i = 1; $i < count($detalleHoras); $i++) {
                if ($detalleHoras[$i]['costo'] < $costoMenor) {
                    $costoMenor = $detalleHoras[$i]['costo'];
                    $indiceHoraMenor = $i;
                }
            }
            $indiceHoraGratis = $indiceHoraMenor;
            $detalleHoras[$indiceHoraGratis]['gratis'] = true;
            $montoPromocion = (float)$detalleHoras[$indiceHoraGratis]['costo'];
            agregarObservacion($observaciones, 'Se aplicó la promoción “hora gratis”: se descontó la hora de menor costo (' . $detalleHoras[$indiceHoraGratis]['horario'] . ').');
        }

        $subtotalDespuesPromocion = round($subtotalCancha - $montoPromocion, 2);
        $descuentoSocio = $codigoSocioValido ? round($subtotalDespuesPromocion * DESCUENTO_SOCIO, 2) : 0.00;
        $subtotalNetoCancha = round($subtotalDespuesPromocion - $descuentoSocio, 2);

        $detalleImplementos = [];
        $totalImplementos = 0.00;
        foreach ($implementosSeleccionados as $implemento) {
            if ($implemento === 'raquetas' && $cancha !== 'padel') {
                agregarObservacion($observaciones, 'Las raquetas solo se alquilan para la cancha de pádel; no se cobraron en esta reserva.');
                continue;
            }
            $precio = (float)$preciosImplementos[$implemento]['precio'];
            $detalleImplementos[] = ['nombre' => $preciosImplementos[$implemento]['nombre'], 'precio' => $precio];
            $totalImplementos = round($totalImplementos + $precio, 2);
        }

        $totalPagar = round($subtotalNetoCancha + $totalImplementos, 2);
        $valorVenta = round($totalPagar / (1 + IGV), 2);
        $montoIGV = round($totalPagar - $valorVenta, 2);
        $codigoReserva = generarCodigoReserva($tarifas[$cancha]['nombre'], $fecha, $horaInicio, $cliente);

        $resultado = [
            'cliente' => $cliente,
            'codigo_socio' => $codigoSocio,
            'socio_valido' => $codigoSocioValido,
            'cancha' => $cancha,
            'nombre_cancha' => $tarifas[$cancha]['nombre'],
            'fecha' => $fecha,
            'dia' => nombreDia($fecha),
            'hora_inicio' => $horaInicio,
            'hora_fin' => $horaInicio + $cantidadHoras,
            'cantidad_horas' => $cantidadHoras,
            'codigo_reserva' => $codigoReserva,
            'detalle_horas' => $detalleHoras,
            'subtotal_cancha' => $subtotalCancha,
            'monto_promocion' => $montoPromocion,
            'subtotal_despues_promocion' => $subtotalDespuesPromocion,
            'descuento_socio' => $descuentoSocio,
            'subtotal_neto_cancha' => $subtotalNetoCancha,
            'detalle_implementos' => $detalleImplementos,
            'total_implementos' => $totalImplementos,
            'valor_venta' => $valorVenta,
            'monto_igv' => $montoIGV,
            'total_pagar' => $totalPagar,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $resultado ? 'Cotización ' . h($resultado['codigo_reserva']) : 'Validación de reserva'; ?> | La Cantera</title>
  <link rel="stylesheet" href="estilos.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a class="brand" href="est_reserva.html" aria-label="La Cantera, inicio">
        <span class="brand-mark" aria-hidden="true">LC</span>
        <span class="brand-copy"><strong>LA CANTERA</strong><small>COMPLEJO DEPORTIVO</small></span>
      </a>
      <span class="header-tag"><span class="status-dot"></span> Sistema de cotización</span>
    </div>
  </header>

  <main class="page-wrap result-wrap">
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
      <section class="card message-card">
        <span class="status-pill status-warning">Formulario requerido</span>
        <h1>Primero registra una reserva</h1>
        <p>Para calcular una cotización, ingresa los datos del cliente y de la cancha desde el formulario.</p>
        <a class="button-primary button-link" href="est_reserva.html">Ir al formulario <span aria-hidden="true">→</span></a>
      </section>
    <?php elseif ($errores !== []): ?>
      <section class="card message-card">
        <span class="status-pill status-error">No se generó la cotización</span>
        <h1>Revisa los datos ingresados</h1>
        <p>Se encontraron los siguientes problemas. Corrígelos y vuelve a enviar el formulario.</p>
        <ul class="error-list">
          <?php foreach ($errores as $error): ?>
            <li><?php echo h($error); ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="button-primary button-link" href="est_reserva.html">Regresar al formulario <span aria-hidden="true">←</span></a>
      </section>
    <?php else: ?>
      <div class="result-topline"><a class="back-link" href="est_reserva.html"><span aria-hidden="true">←</span> Nueva cotización</a><span class="status-pill status-success"><span class="status-dot"></span> Cotización calculada</span></div>

      <section class="quote-banner">
        <div>
          <p class="eyebrow eyebrow-light">COTIZACIÓN DE RESERVA</p>
          <h1>Tu reserva, <span>al detalle.</span></h1>
          <p>Revisa el cálculo por hora y el resumen antes de confirmar la reserva.</p>
        </div>
        <div class="quote-code"><small>CÓDIGO DE RESERVA</small><strong><?php echo h($resultado['codigo_reserva']); ?></strong><span>Generado correctamente</span></div>
      </section>

      <section class="card reservation-card">
        <div class="section-heading"><div><p class="eyebrow">RESUMEN DE LA SOLICITUD</p><h2>Datos de la reserva</h2></div><span class="court-chip"><?php echo h($resultado['nombre_cancha']); ?></span></div>
        <div class="reservation-grid">
          <div class="data-item"><span>Cliente</span><strong><?php echo h($resultado['cliente']); ?></strong></div>
          <div class="data-item"><span>Fecha</span><strong><?php echo h(date('d/m/Y', strtotime($resultado['fecha']))); ?></strong><small><?php echo h(ucfirst($resultado['dia'])); ?></small></div>
          <div class="data-item"><span>Horario</span><strong><?php echo h(str_pad((string)$resultado['hora_inicio'], 2, '0', STR_PAD_LEFT) . ':00 – ' . str_pad((string)$resultado['hora_fin'], 2, '0', STR_PAD_LEFT) . ':00'); ?></strong><small><?php echo h((string)$resultado['cantidad_horas']); ?> hora(s)</small></div>
          <div class="data-item"><span>Condición</span><strong><?php echo $resultado['socio_valido'] ? 'Socio válido' : 'Público general'; ?></strong><small><?php echo $resultado['socio_valido'] ? h($resultado['codigo_socio']) : 'Sin descuento de socio'; ?></small></div>
        </div>
      </section>

      <section class="card detail-card">
        <div class="section-heading"><div><p class="eyebrow">CÁLCULO DEL ALQUILER</p><h2>Detalle por hora</h2></div><span class="table-caption">Tarifas en soles (S/)</span></div>
        <div class="table-scroll"><table class="detail-table">
          <thead><tr><th>Horario</th><th>Franja</th><th>Tarifa base</th><th>Recargo</th><th>Costo por hora</th><th>Promoción</th></tr></thead>
          <tbody>
          <?php foreach ($resultado['detalle_horas'] as $fila): ?>
            <tr class="<?php echo $fila['gratis'] ? 'free-hour-row' : ''; ?>">
              <td><strong><?php echo h($fila['horario']); ?></strong></td>
              <td><span class="time-band <?php echo h($fila['franja']); ?>"><?php echo h(ucfirst($fila['franja'])); ?></span></td>
              <td><?php echo h(formatearMonto((float)$fila['tarifa_base'])); ?></td>
              <td><?php echo h(formatearMonto((float)$fila['recargo'])); ?></td>
              <td class="money-cell"><?php echo h(formatearMonto((float)$fila['costo'])); ?></td>
              <td><?php if ($fila['gratis']): ?><span class="free-badge">GRATIS</span><?php else: ?><span class="muted-dash">—</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="table-footnote">El costo por hora muestra la tarifa con el recargo aplicable, antes de la promoción. La hora marcada como GRATIS se descuenta en el resumen.</p>
      </section>

      <div class="summary-layout">
        <section class="card equipment-card">
          <p class="eyebrow">SERVICIOS ADICIONALES</p><h2>Implementos</h2>
          <?php if ($resultado['detalle_implementos'] === []): ?>
            <div class="empty-state"><span aria-hidden="true">—</span><p>No se seleccionaron implementos cobrables.</p></div>
          <?php else: ?>
            <ul class="equipment-list">
              <?php foreach ($resultado['detalle_implementos'] as $implemento): ?>
                <li><span><?php echo h($implemento['nombre']); ?></span><strong><?php echo h(formatearMonto((float)$implemento['precio'])); ?></strong></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <div class="equipment-total"><span>Total implementos</span><strong><?php echo h(formatearMonto((float)$resultado['total_implementos'])); ?></strong></div>
        </section>

        <section class="card price-card">
          <p class="eyebrow">RESUMEN DE MONTOS</p><h2>Detalle de pago</h2>
          <div class="price-line"><span>Subtotal de cancha</span><strong><?php echo h(formatearMonto((float)$resultado['subtotal_cancha'])); ?></strong></div>
          <div class="price-line discount-line"><span>Promoción: hora gratis</span><strong>− <?php echo h(formatearMonto((float)$resultado['monto_promocion'])); ?></strong></div>
          <div class="price-line"><span>Subtotal después de promoción</span><strong><?php echo h(formatearMonto((float)$resultado['subtotal_despues_promocion'])); ?></strong></div>
          <div class="price-line discount-line"><span>Descuento de socio (20 %)</span><strong>− <?php echo h(formatearMonto((float)$resultado['descuento_socio'])); ?></strong></div>
          <div class="price-line"><span>Alquiler neto de cancha</span><strong><?php echo h(formatearMonto((float)$resultado['subtotal_neto_cancha'])); ?></strong></div>
          <div class="price-line"><span>Implementos</span><strong><?php echo h(formatearMonto((float)$resultado['total_implementos'])); ?></strong></div>
          <div class="price-divider"></div>
          <div class="price-line"><span>Valor de venta (sin IGV)</span><strong><?php echo h(formatearMonto((float)$resultado['valor_venta'])); ?></strong></div>
          <div class="price-line"><span>IGV incluido (18 %)</span><strong><?php echo h(formatearMonto((float)$resultado['monto_igv'])); ?></strong></div>
          <div class="total-line"><span>Total a pagar</span><strong><?php echo h(formatearMonto((float)$resultado['total_pagar'])); ?></strong></div>
        </section>
      </div>

      <section class="card observations-card">
        <div class="section-heading"><div><p class="eyebrow">NOTAS DEL SISTEMA</p><h2>Observaciones</h2></div></div>
        <?php if ($observaciones === []): ?>
          <p class="no-observations"><span aria-hidden="true">✓</span> No se generaron observaciones para esta cotización.</p>
        <?php else: ?>
          <ul class="observation-list">
            <?php foreach ($observaciones as $observacion): ?><li><?php echo h($observacion); ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <div class="result-actions"><a class="button-primary button-link" href="est_reserva.html">Crear otra cotización <span aria-hidden="true">→</span></a><button class="button-secondary" type="button" onclick="window.print()">Imprimir cotización</button></div>
    <?php endif; ?>
  </main>

  <footer class="site-footer"><span>LA CANTERA <span class="footer-separator">/</span> Sistema académico de reservas</span><span>Desarrollado por: [REEMPLAZAR CON TU NOMBRE COMPLETO] <span class="footer-separator">·</span> Generado: <?php echo h(date('d/m/Y H:i:s')); ?></span></footer>
</body>
</html>
