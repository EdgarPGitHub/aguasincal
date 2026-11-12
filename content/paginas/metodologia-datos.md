---
titulo: De dónde salen los datos
seo_title: "Metodología: datos de dureza del agua y cálculos | AguaSinCal"
descripcion: "Fuentes oficiales, escala de dureza y supuestos de cálculo que usamos para recomendar descalcificadores y ósmosis en cada municipio."
entradilla: "Fuentes, escala y supuestos que usamos en las fichas de cada municipio."
ruta: /metodologia-datos/
estado: publicado
actualizado: 2026-11-12
---

## Fuentes

- **Calidad del agua:** Sistema de Información Nacional de Aguas de Consumo (**SINAC**), del Ministerio de Sanidad. Las empresas que gestionan el abastecimiento están obligadas a publicar en él los análisis de cada zona de abastecimiento.
- **Municipios y población:** Instituto Nacional de Estadística (**INE**).
- **Coordenadas de municipios:** centro del término municipal, calculado con los límites municipales del Instituto Geográfico Nacional (**IGN**).

Consultamos el SINAC de forma periódica: cada semana se actualizan los municipios cuyos datos son más antiguos. Junto a cada dato indicamos la fecha de la muestra y la de la consulta. Solo creamos ficha propia de los municipios de 1.000 habitantes o más con muestras de los últimos 24 meses.

## Cómo calculamos la dureza de un municipio

Un municipio puede recibir agua de varias **redes de abastecimiento** (zonas), cada una con su origen y su tratamiento. Para cada red tomamos el último valor de **dureza total** publicado en el SINAC. Si una red no publica la dureza pero sí el calcio y el magnesio, la calculamos con la fórmula habitual: dureza (mg/L de CaCO₃) = 2,497 × calcio + 4,118 × magnesio. Lo indicamos con un asterisco.

La dureza del municipio es la **media ponderada por la población** que abastece cada red, y mostramos también el valor de cada zona. Descartamos las redes que no abastecen a población (aeropuertos, industrias, puertos). El resto de parámetros (nitratos, sodio, pH, etc.) se calculan igual y se comparan con los valores paramétricos del **Real Decreto 3/2023**.

## Cómo elaboramos las guías

Las guías y las páginas de servicio las escribe el equipo de AguaSinCal con información de la normativa, de los datos oficiales y de la documentación técnica de los fabricantes. No incluimos precios ni cifras que no podamos respaldar. Si un técnico revisa una guía, aparece su nombre. Si ves un error, escríbenos a %titular.email% y lo corregimos.

## Unidades

1 grado francés (°fH) equivale a 10 mg/L de carbonato cálcico (CaCO₃). Otras equivalencias útiles:

| Unidad | Equivale a |
|---|---|
| 1 °fH | 10 mg/L de CaCO₃ |
| 1 °dH (grado alemán) | 1,78 °fH |
| 1 mmol/L | 10 °fH |

## Escala de dureza que usamos

No hay una única escala oficial en España. Para que las recomendaciones sean claras usamos esta:

| Dureza | Clasificación |
|---|---|
| Menos de 15 °fH | Blanda |
| 15 a 30 °fH | Moderadamente dura |
| 30 a 40 °fH | Dura |
| Más de 40 °fH | Muy dura |

La dureza no es un parámetro sanitario: el agua dura es apta para el consumo. Nuestras recomendaciones buscan proteger la instalación y mejorar el sabor, no corregir un problema de salud.

## Cálculo del tamaño del descalcificador

Usamos un consumo de 140 litros por persona y día, una dureza final de 8 °fH, una capacidad de la resina de 5 °fH·m³ por litro, 150 g de sal por litro de resina en cada regeneración y unos 7 días entre regeneraciones. El resultado se redondea al tamaño comercial más cercano por arriba. Es una estimación: un técnico debe medir la dureza en tu casa y revisar tu instalación.

## Errores y actualizaciones

Si ves un dato que no cuadra con tu factura o con un análisis reciente, escríbenos y lo revisamos.
