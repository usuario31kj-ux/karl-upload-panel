# Lineamientos de Arquitectura y Seguridad para APIs en GCP — Izipay 2026

**Versión:** 1.0
**Fecha de consolidación:** 04-may-2026
**Autor de la consolidación:** Karl Mollan (mc2188 — Senior Data & AI Engineer, Izipay)
**Fuentes primarias consolidadas:**

1. Correo *Revisión arquitectura estándar proyectos IA* — Carlos Jara (Arquitectura de Soluciones), 04-may-2026.
2. Correo *Revisión de seguridad en APIs en GCP — Iniciativa Data (Arq+Seg)* — Jeremy Cruz (Seguridad de la Información), iteraciones 21-ene a 17-feb-2026.
3. PDF *Estándar de Seguridad en Aplicaciones Izipay v1.0* (vigencia 01-abr-2025).
4. Diagramas de red GCP No-Productivo y Productivo (Carlos Jara, mayo-2026).
5. Documento ARQIZIPAY-REF-002 *Diseño de APIs OpenAPI v1.0* (referenciado).

**Alcance:** este documento condensa el conjunto de lineamientos que rigen, a partir de mayo de 2026, todo proyecto de apificación, IA y backoffice nuevo o renovado dentro del ecosistema GCP/Azure de Izipay. Está redactado de forma tal que su contenido sea **transferible a cualquier empresa de servicios financieros o procesadora de pagos** que requiera operar bajo PCI-DSS, ISO 27001 y normativa de protección de datos personales.

---

## 1. Marco conceptual

Cualquier API moderna en una organización financiera regulada debe responder simultáneamente a tres ejes:

1. **Confiabilidad operativa** — disponibilidad, latencia, capacidad de recuperación ante desastre, observabilidad.
2. **Seguridad por diseño** — perímetro, identidad, datos, aplicación, código, dependencias.
3. **Gobernanza de contratos** — un contrato OpenAPI versionado, alineado a una taxonomía de dominio, que pueda ser leído por desarrolladores, arquitectos, equipos de seguridad y herramientas automatizadas.

Estos lineamientos no son recomendaciones aisladas: son la materialización en GCP de los principios de *Defense in Depth*, *Zero Trust*, *Mínimo Privilegio* y *Separación de Responsabilidades*, aplicados a un patrón cloud-nativo basado en Cloud Run y servicios gestionados.

---

## 2. Modelo de proyectos: Host + Service Projects sobre Shared VPC

### 2.1 Concepto

La organización en GCP se estructura bajo el patrón **Shared VPC** documentado por Google en su guía de mejores prácticas de diseño de VPC. Un único proyecto, denominado *Host Project*, es propietario de la red compartida y de los servicios transversales. Cada iniciativa de negocio (REVIDOC, Buzones IA, Chatbot Postventa, etc.) se aloja en un *Service Project* independiente que se conecta a la red compartida mediante *Shared VPC Connectivity*.

### 2.2 Componentes del Host Project

El Host concentra todo lo que debe ser único, controlado y reutilizado:

- **Shared VPC Network** con sus subnets segmentadas por proyecto y por capa de servicio.
- **Cloud DNS** corporativo, con zonas privadas para resolución interna y zonas públicas controladas.
- **Cloud Firewall Rules** centralizadas, gestionadas por el equipo de Infraestructura.
- **Cloud NAT** como única salida controlada hacia Internet para todos los Service Projects.
- **Cloud Routes** y **Cloud Logging** para enrutamiento y telemetría centralizada.
- **Secret Manager** corporativo para los secretos transversales (no los específicos del proyecto, que viven en su Service Project).
- **Artifact Registry** para imágenes de contenedor con escaneo de malware habilitado.
- **API Management** (uno por entorno: Desarrollo, Certificación, Producción).
- **Global External Regional Cloud Load Balancing** con **WAF Cloud Armor** anclado.
- **Cloud IAM** y la integración con **Microsoft Entra ID** como IDP corporativo.

### 2.3 Componentes de cada Service Project

Cada proyecto de negocio recibe un Service Project con una subnet asignada por arquitectura. Dentro vive su carga de trabajo:

- Una o varias instancias de **Cloud Run**, configuradas con `--ingress=internal` o `internal-and-cloud-load-balancing`, jamás con ingress público.
- **Direct VPC egress** activado, evitando el uso del antiguo Serverless VPC Connector.
- **AlloyDB** (o Cloud SQL) cuando se requiere persistencia transaccional, siempre con Private IP y ningún endpoint público.
- **Service Accounts dedicadas** por servicio, con permisos de mínimo privilegio asignados por secreto y por recurso.

### 2.4 Topología por entorno

**Entorno No-Productivo (Desarrollo y Certificación)**

Una sola Shared VPC alberga ambos ambientes, segmentados por región. La región us-west1 hospeda Desarrollo y la us-east4 hospeda Certificación. Cada Service Project recibe dos subnets: una para sus cargas de trabajo principales (rango 10.0.1.0/xx en Dev, 10.1.1.0/xx en Cert) y otra para servicios transversales (10.0.2.0/xx en Dev, 10.1.2.0/xx en Cert). El API Management se duplica por ambiente, y el WAF Cloud Armor se activa solamente en Certificación, ya que Desarrollo no expone tráfico productivo.

**Entorno Productivo**

Producción tiene una Shared VPC dedicada, físicamente separada del entorno No-Productivo. Se opera en arquitectura activa-pasiva o activa-activa entre dos regiones: us-east4 como principal y us-west1 como secundaria de continuidad de negocio. Toda la pila se replica en ambas regiones: subnets, Cloud Run, AlloyDB, API Management. El WAF Cloud Armor y el Global External Regional Load Balancer son obligatorios. La salida a Internet se concentra exclusivamente en el Cloud NAT del Host.

### 2.5 Por qué este modelo

El modelo Shared VPC con Service Projects entrega cinco beneficios estructurales:

1. **Centralización de seguridad de red** — el equipo de Infraestructura controla las reglas de firewall, las rutas y la salida NAT, sin depender de cada equipo de proyecto.
2. **Aislamiento por workload** — cada iniciativa vive en su propio Service Project, con sus propias cuotas, permisos, billing y service accounts.
3. **Reutilización de componentes costosos** — Cloud Armor, Load Balancer, API Management se compran una vez y se sirven a todas las iniciativas.
4. **Trazabilidad fiscal y operativa** — el billing por proyecto permite reportar costos por iniciativa.
5. **Cumplimiento regulatorio** — la segmentación por subnet y la salida única por Cloud NAT facilitan auditorías PCI-DSS y demuestran control de tráfico.

---

## 3. Identidad y autenticación: el patrón security-api-v1

### 3.1 IDP único: Microsoft Entra ID

Izipay establece **Microsoft Entra ID** (anteriormente Azure AD) como su único proveedor de identidad corporativa. Toda autenticación de aplicaciones internas, integraciones máquina-a-máquina y validación de tokens debe pasar por Entra ID. Esto homologa con los proyectos legacy en Azure y centraliza el ciclo de vida de identidades en un solo plano.

### 3.2 Microservicio transversal de seguridad

Para evitar que cada API reimplemente la lógica de autenticación contra Entra ID, se introduce un microservicio dedicado: **`security-api-v1`**. Este microservicio reside en el Host Project bajo la taxonomía **Soporte** y es el único componente autorizado a:

- Solicitar tokens contra Entra ID mediante OAuth 2.0.
- Validar la firma, expiración y audiencia de tokens JWT entrantes.
- Consultar y aplicar la política de scopes y roles asociada a cada cliente.
- Renovar tokens con refresh-token cuando aplique.

Las APIs de negocio nunca hablan directamente con Entra ID. El API Gateway delega la validación de cada request en `security-api-v1` y enruta solamente cuando la respuesta es positiva.

### 3.3 Por qué centralizar este componente

Cuatro razones operativas justifican el patrón:

1. **Un solo punto de evolución** — cuando Entra ID rote credenciales, cambie endpoints o introduzca nuevos tipos de token, sólo `security-api-v1` se modifica.
2. **Un solo punto de auditoría** — todos los intentos de autenticación se registran en un mismo servicio, simplificando investigación de incidentes.
3. **Un solo punto de configuración de política** — los scopes y roles se definen una vez y se aplican a todas las APIs.
4. **Reducción de superficie de ataque** — las claves de cliente OAuth y los secretos de Entra ID nunca tocan las APIs de negocio.

---

## 4. Diseño de contratos OpenAPI 3 alineados a BIAN

### 4.1 OpenAPI 3 como contrato canónico

Todo servicio expuesto debe contar con un contrato **OpenAPI 3** versionado en repositorio. Este contrato es la fuente de verdad para el desarrollo, las pruebas, la generación de SDKs, la validación en API Management y la documentación que consume Arquitectura.

Las convenciones obligatorias en el diseño de rutas y recursos son:

- Idioma **inglés**, en plural y con **spinal-case**. Ejemplos: `/v1/reviewed-documents`, `/v1/post-sale-chats`, `/v1/inbox-messages`.
- Versionado en la URL bajo el segmento `/v{n}/`.
- Nombres semánticos del recurso, no del verbo. Las acciones se expresan con métodos HTTP (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`).
- Subrecursos navegables y paginación con cursor o `page_number/rows_of_page` cuando el conjunto es grande.

### 4.2 Agrupación por dominios BIAN

Los payloads de request y response no son estructuras planas. Se agrupan por **Domain Services** según la taxonomía BIAN (Banking Industry Architecture Network). Este patrón abandona el diseño plano tipo "todos los campos al mismo nivel" y obliga a expresar la información en bloques semánticos.

Por ejemplo, en lugar de un payload plano:

```json
{ "merchant_code": "...", "merchant_name": "...", "txn_amount": "...", "txn_date": "..." }
```

se exige una agrupación BIAN:

```json
{
  "merchant": { "code": "...", "name": "..." },
  "transaction": { "amount": "...", "date": "..." }
}
```

Esta práctica facilita la evolución de cada dominio sin romper compatibilidad y permite que múltiples APIs compartan los mismos sub-objetos.

### 4.3 Headers obligatorios

**En el request, el cliente debe enviar:**

- `authorization` — Bearer token JWT emitido por Entra ID.
- `app-name` — identificador de la aplicación cliente.
- `caller-name` — identificador del consumidor humano o sistema invocante.
- `request-date` — timestamp del cliente en formato ISO 8601 con zona horaria.

**El API Gateway inyecta automáticamente:**

- `request-id` — UUID único por petición que viaja en el resto del flujo y permite la trazabilidad end-to-end. Si llega vacío o ausente, el gateway lo genera.

**En la response, el servicio debe inyectar las cabeceras de seguridad:**

- `X-XSS-Protection: 1; mode=block`
- `X-Content-Type-Options: nosniff`
- `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy` — configurada según el frontend que consuma.

### 4.4 Taxonomía de servicios

Las APIs se categorizan en taxonomías que reflejan su naturaleza:

- **Backend Negocio** — APIs de dominio que exponen capacidades de un proceso de negocio (REVIDOC analiza contratos, Chatbot atiende postventa, etc.).
- **Soporte** — APIs transversales (security-api-v1, logging, configuración, feature flags).
- **Integración** — APIs que orquestan flujos entre sistemas legacy y nube.

Esta taxonomía ordena el catálogo de servicios y facilita el descubrimiento.

---

## 5. Seguridad perimetral

### 5.1 La pila perimetral en GCP

Toda petición que llega desde Internet recorre una pila de cinco componentes antes de tocar el Cloud Run de la aplicación:

1. **Cloud DNS** — resolución del nombre público de la API.
2. **Certificate Manager + SSL termination en el Load Balancer** — TLS 1.2 mínimo, idealmente TLS 1.3.
3. **Global External Regional Load Balancer** — distribuye carga entre regiones y entrega SSL terminado al WAF.
4. **WAF Cloud Armor** — aplica reglas OWASP Top 10, lista negra de IPs, geo-blocking si aplica, y rate-limiting de bajo nivel.
5. **API Management** — valida el contrato OpenAPI, aplica políticas de CORS y rate-limiting de aplicación, valida el token JWT mediante delegación a `security-api-v1`, y enruta al Cloud Run interno.

Sólo después de pasar esta pila, el Cloud Run del Service Project recibe la petición — y lo hace por su endpoint privado, jamás por uno público.

### 5.2 Políticas obligatorias en el perímetro

- **CORS restrictivo** — orígenes explícitamente listados, no comodín `*`. La política se define en API Management y se ajusta por API.
- **Rate-limiting** — configurado por cliente, por endpoint y por método. Se rechazan ráfagas que excedan el contrato.
- **mTLS opcional** — para integraciones máquina-a-máquina críticas, se habilita autenticación mutua TLS además del JWT. Esto se exige cuando el consumidor es un sistema interno regulado (por ejemplo, OM consumiendo APIs de Data).
- **Restricción por IP de salida** — los recursos de back que consumen Azure (OpenAI, AI Search) se filtran por IP de origen, ya que sólo el Cloud NAT del Host es la fuente legítima.

### 5.3 Lo que está prohibido

- Cloud Run con `--ingress=all` o público, sin excepción.
- Secret Manager con permisos de lectura para roles amplios; solo Service Accounts específicas.
- API Keys hardcodeadas en código, en variables de entorno no gestionadas, o en repositorios.
- Comunicación HTTP no cifrada entre componentes, incluso dentro de la VPC.
- Exposición de paneles administrativos (Swagger UI, /admin, /metrics) sin autenticación.

---

## 6. Seguridad de la aplicación y del código

### 6.1 Pipeline de DevSecOps

Cada commit a la rama principal de un proyecto debe atravesar tres compuertas automáticas antes de llegar a Artifact Registry:

1. **Análisis estático con SonarQube** — calidad de código, vulnerabilidades conocidas, deuda técnica medida. Si el quality gate falla, el merge se bloquea.
2. **Escaneo de dependencias** — Snyk, Trivy o equivalente sobre `requirements.txt` / `package.json`. Se rechazan dependencias con CVE críticas.
3. **Escaneo de imagen en Artifact Registry** — el repositorio tiene activado el escaneo automático de malware sobre cada imagen empujada.

### 6.2 Gestión de secretos

- Cero credenciales en código, en `.env` versionados, o en variables de entorno fijas.
- Cada secreto reside en **Secret Manager** del Service Project correspondiente.
- El Cloud Run lee el secreto en runtime mediante su Service Account.
- Los secretos están **versionados** y la rotación se ejecuta como tarea calendarizada.
- Las claves de cifrado, cuando aplican, se gestionan en **Cloud KMS** con CMEK (Customer-Managed Encryption Keys) para los buckets, AlloyDB y los topics de Pub/Sub que carguen información sensible.

### 6.3 Cabeceras de seguridad en la aplicación

Las cabeceras enumeradas en la sección 4.3 se inyectan en **toda** respuesta del Cloud Run, tanto las exitosas como las de error. Esto incluye los endpoints de salud y diagnóstico, que también deben rechazar requests no autenticados o exponer información mínima.

---

## 7. Patrón especial: agentes de IA

REVIDOC, los Buzones IA y el Chatbot Postventa caen en una categoría que requiere controles adicionales sobre los descritos: son **agentes de IA**. Esto significa que el flujo de la aplicación incluye un modelo de lenguaje que toma decisiones, llama a herramientas y produce contenido.

### 7.1 Identidad por agente

Cada agente, entendido como un componente que invoca al LLM con un propósito particular (extractor de texto, comparador de cláusulas, clasificador de riesgo, supervisor), tiene su propia Service Account. No se reutilizan SAs entre agentes. La SA tiene exactamente los permisos que el agente necesita y nada más.

### 7.2 Mitigación de prompt injection

El contenido que entra al LLM (un PDF de contrato, un mensaje de cliente, un correo del buzón) es por definición **no confiable**. Puede contener instrucciones inyectadas que intenten subvertir el prompt del sistema. La mitigación tiene dos capas:

1. **Sanitización de entrada** — antes de concatenar el contenido al prompt, se le aplica detección de patrones de inyección (frases como "ignore previous instructions", "you are now", marcadores de sistema, etc.) y se delimita explícitamente con marcadores XML o tags estructurados.
2. **Agente supervisor** — un segundo agente, con un prompt distinto y aislado, valida la respuesta del agente principal antes de entregarla al usuario. Si la respuesta contiene información de un comercio que el usuario no debería ver, o intenta ejecutar instrucciones inyectadas, el supervisor la bloquea.

### 7.3 Separación estricta de roles

Un agente no puede simultáneamente generar contenido, ejecutar código y leer secretos. Estos tres roles se separan en componentes distintos con SAs distintas:

- El **agente generador** tiene permisos para invocar al LLM y escribir resultados.
- El **agente ejecutor** (cuando aplica) tiene permisos para llamar APIs específicas en una lista blanca cerrada.
- El **agente lector de secretos** no existe como tal; los secretos los entrega el plano de configuración a cada componente en arranque, no se exponen a un agente en runtime.

### 7.4 Lista blanca de herramientas

El conjunto de tools que un agente puede invocar es explícitamente declarado y limitado. Para REVIDOC, por ejemplo, las tools válidas son: lectura de Cloud Storage, generación de embeddings vía Azure, llamada al endpoint de Azure OpenAI, búsqueda en Azure AI Search, escritura en AlloyDB. Cualquier otra invocación se rechaza en la capa de orquestación.

### 7.5 Límites operativos

- **Timeouts por invocación al LLM** — máximos configurados, no infinitos.
- **Tamaño máximo de prompt** — se trunca o rechaza si excede.
- **Tasa máxima de llamadas por sesión** — para contener costos y abusos.
- **Filtros de contenido** — los servicios de Azure OpenAI y Vertex AI traen filtros nativos para odio, violencia, contenido sexual y autolesión. Se mantienen activados y se monitorea su tasa de bloqueo.

---

## 8. Tratamiento de datos sensibles

### 8.1 La regla central

Toda información que cae en logs, historial de chat, base de datos auxiliar o cualquier almacén distinto del store transaccional principal debe tratarse según una tabla de ofuscación obligatoria. Esta tabla aplica a **todos** los proyectos.

| Dato | Tratamiento |
|---|---|
| Nombres y apellidos | Sólo nombre en claro |
| DNI / NIE / otros documentos de identidad | Ofuscar dejando solo los 4 últimos dígitos: `****1111` |
| Fecha de nacimiento | No almacenar |
| Cuenta corriente / IBAN | No almacenar |
| Domicilios | No almacenar |
| Teléfono de contacto | Ofuscar: `*****1234` |
| Email | Ofuscar: `a****@dom.com` |
| PAN (número completo de tarjeta) | No almacenar |
| CVV / CVC / CVV2 | No almacenar |
| PIN | No almacenar |
| Fecha de caducidad de la tarjeta | No almacenar |
| Credenciales y llaves | No almacenar |

### 8.2 Separación de stores

Se exige separación física entre las bases de datos que almacenan logs y las que almacenan información de negocio o historial conversacional. Esto evita que una vulnerabilidad en el plano de logging exponga datos transaccionales, y permite políticas de acceso y retención diferenciadas.

### 8.3 Cifrado en reposo y en tránsito

- **En reposo** — todo store sensible (AlloyDB, Cloud Storage, Pub/Sub messages persistidos) usa cifrado de Google por defecto, y CMEK para los datos clasificados como sensibles según la política corporativa.
- **En tránsito** — TLS 1.2 mínimo en todas las conexiones, incluso intra-VPC. Las conexiones a AlloyDB usan certificado de servidor con verificación.

### 8.4 Retención

La retención mínima de logs operativos y de auditoría es de **2 años**, según los estándares PCI-DSS y la ley peruana de protección de datos personales. La retención de datos transaccionales sigue las políticas de cada dominio, típicamente entre 5 y 10 años.

---

## 9. Observabilidad y SIEM

### 9.1 Logs estructurados

Cada Cloud Run emite logs en formato **JSON estructurado** con cinco campos obligatorios:

- `WHEN` — timestamp ISO 8601 con zona horaria.
- `WHERE` — servicio, versión semántica, región, instancia.
- `SEVERITY` — uno de `DEBUG`, `INFO`, `WARN`, `ERROR`, `CRITICAL`.
- `WHAT` — operación lógica que se está ejecutando (extract_pdf, validate_token, classify_risk, etc.).
- `WHY` — contexto que incluye `request-id`, `caller-name`, `app-name` y, cuando aplica, `merchant_code` ofuscado.

Adicionalmente se incluyen latencia, tamaño de payload y resultado de la operación.

### 9.2 Pipeline de telemetría

Los logs entran a **Cloud Logging**, donde residen para consulta corta (90 días por defecto). Un sink los exporta a **Pub/Sub**, donde un consumidor los formatea a **CEF** o **LEEF** y los entrega al SIEM corporativo. El SIEM aplica reglas de detección de anomalías, intentos de fuerza bruta, errores de autenticación masivos y patrones conocidos de ataque.

### 9.3 Métricas de aplicación

Cada API expone métricas estándar a Cloud Monitoring:

- Latencia p50, p95, p99 por endpoint.
- Tasa de errores 4xx y 5xx.
- Throughput (RPS).
- Saturación de Cloud Run (instancias en uso vs. máximas).
- Métricas de negocio específicas — tiempo medio de análisis de contrato en REVIDOC, tasa de transferencia humana en Chatbot, etc.

### 9.4 Trazas distribuidas

Para flujos que atraviesan más de tres componentes, se habilita **Cloud Trace** o **OpenTelemetry**, propagando el `request-id` como correlation ID a través de todos los saltos.

---

## 10. Auditoría aplicativa

Cuando una aplicación tiene usuarios humanos (no es solo machine-to-machine), debe cumplir el estándar de auditoría aplicativa del PDF *Estándar de Seguridad en Aplicaciones Izipay v1.0*.

### 10.1 Eventos auditables

Se registran como mínimo los siguientes eventos:

- Creación, modificación y eliminación de usuarios y perfiles.
- Modificación de opciones asignadas a un perfil.
- Login y logout, con histórico de **2 años**.
- Cambios de contraseña (cuando aplica auth local).
- Procesamiento, visualización, modificación, eliminación, importación y exportación de datos personales.
- Cualquier acción sobre información transaccional.

### 10.2 Datos del evento

Cada registro contiene: fecha-hora, username, hostname, IP, acción, detalle, identificador del recurso afectado.

### 10.3 Reportería obligatoria

La aplicación expone, dentro de su propia interfaz, dos reportes visualizables y exportables:

**Reporte Usuarios vs Perfil:**
- Código del usuario, nombres y apellidos, tipo (interno o comercio), tipo de autenticación (local o LDAP), estado, fecha de creación, fecha de último acceso, perfil asignado.

**Reporte Perfil vs Opciones:**
- Nombre y descripción del perfil, listado de opciones habilitadas, fecha de creación, fecha de última modificación.

### 10.4 Política de contraseñas (sólo aplicaciones con auth local)

Para aplicaciones que excepcionalmente no se integran a Entra ID o CIAM:

- Longitud mínima 12 caracteres.
- Complejidad: al menos una mayúscula, una minúscula, un número y un carácter especial.
- No repetir ninguna de las últimas 10 contraseñas.
- Caducidad cada 90 días.
- Bloqueo a los 3 intentos fallidos, con desbloqueo por correo.
- Cambio forzado en el primer login y al resetear.
- Cifrado de la contraseña almacenada con **bcrypt + pepper**.
- Cierre de sesión por inactividad a los 15 minutos.
- Todos los parámetros configurables sin requerir despliegue.

---

## 11. Recursos Azure: cuando coexisten con GCP

Mientras Izipay no termine la migración a GCP nativo, los proyectos IA siguen consumiendo Azure OpenAI y Azure AI Search. Estos recursos deben configurarse según el *Azure security baseline*.

### 11.1 Acceso

- Acceso público deshabilitado o, como mínimo, filtrado por IP. Sólo el Cloud NAT del Host puede llegar.
- Acceso administrativo por RBAC con principio de mínimo privilegio. En Producción, sólo el equipo de Infraestructura tiene roles de owner sobre los recursos.

### 11.2 Autenticación a los modelos

- El consumo de Azure OpenAI y Azure AI Search se realiza mediante **token de Entra ID**, no mediante API Key local. La aplicación obtiene el token en runtime y lo presenta como `Authorization: Bearer ...`.
- Una vez funcional el flujo por token, se **deshabilita** la API Key local del recurso.

### 11.3 Logs

- Diagnostic settings habilitados en cada recurso, exportando a un Log Analytics Workspace centralizado.
- Los eventos de uso, errores de autenticación y bloqueos por filtro de contenido se monitorean activamente.

### 11.4 Versionado de secretos

Cuando un secreto reside en Secret Manager (GCP) o en Key Vault (Azure), está versionado y la versión activa se referencia explícitamente. Las versiones anteriores quedan disponibles para rollback durante un periodo configurado.

---

## 12. Frontends: cuándo aplican y cómo

Cuando un proyecto incluye una interfaz web para usuarios humanos, el frontend se diseña con su propia capa de seguridad:

- **DNS público + Certificate Manager** para el dominio del frontend.
- **WAF Cloud Armor** anclado al Load Balancer del frontend, igual que con APIs.
- **Identity-Aware Proxy (IAP)** para frontends internos cuyos usuarios son empleados de Izipay autenticados con Entra ID.
- **Firebase Auth** o un CIAM equivalente para frontends destinados a comercios o clientes externos.
- Frontend hospedado en Cloud Run con **Server-Side Rendering** o estáticos en Cloud Storage detrás del Load Balancer.
- Comunicación al backend exclusivamente vía API Management, jamás directa al Cloud Run del backend.

Las cabeceras de seguridad del frontend incluyen un Content-Security-Policy estricto, con orígenes whitelisted para scripts, estilos y conexiones.

---

## 13. Patrones por tipo de API

A partir de los lineamientos generales emergen dos patrones repetibles, ambos compatibles con la arquitectura Shared VPC.

### 13.1 Patrón A — APIs de consulta a base de datos

Aplica a APIs cuyo rol es exponer datos persistentes con lógica predominantemente declarativa: API Real Time, Query Gateway, IExpress Cupones, Ficha Inteligente del comercio.

La lógica de negocio reside en **vistas y funciones de PostgreSQL** o equivalente, optimizada por el motor. Cloud Run actúa como capa delgada que valida el request, aplica paginación y formato, y devuelve. Esto reduce código de aplicación, aprovecha el optimizador de la base y simplifica el mantenimiento.

### 13.2 Patrón B — APIs con orquestación de IA

Aplica a APIs cuyo rol es orquestar un flujo multi-paso que incluye un modelo de lenguaje: REVIDOC, Buzones IA, Chatbot Postventa, DataAgent, Fraude NomCom.

La lógica de orquestación reside en Cloud Run y consume servicios externos (Azure OpenAI, Azure AI Search, Vertex AI) según la naturaleza del proyecto. El rate-limiting es crítico aquí porque las llamadas al LLM son costosas, y la observabilidad debe rastrear el costo por petición.

Ambos patrones comparten la misma capa de seguridad perimetral, autenticación por security-api-v1 e identidad por Entra ID. Lo que cambia es la naturaleza del backend.

---

## 14. Aplicabilidad transferible

Aunque este documento se redacta en el contexto de Izipay, su contenido es directamente reutilizable en cualquier organización que cumpla las siguientes condiciones:

1. Procesa datos sensibles bajo regulaciones tipo PCI-DSS, ISO 27001, LGPD, GDPR o similares.
2. Opera infraestructura en GCP, o evalúa migrar desde otro hyperscaler.
3. Mantiene una mezcla de cargas legacy en otra nube (Azure, AWS) y cargas nuevas en GCP.
4. Requiere centralizar identidad en un IDP corporativo (Entra ID, Okta, Auth0, Ping).
5. Tiene proyectos de IA que procesan documentación interna o conversaciones con clientes.

Lo que cambia entre empresas es la nomenclatura de los recursos, los nombres de los proyectos, los rangos IP y la elección específica de WAF e IDP. La estructura del modelo —Host Project con Shared VPC, Service Projects por iniciativa, microservicio transversal de seguridad, OpenAPI 3 con BIAN, pipeline DevSecOps, separación de stores, observabilidad estructurada— es un patrón estable y replicable.

---

## 15. Checklist operativa para un proyecto nuevo

Cuando se arranca un proyecto IA o de apificación bajo este marco, las siguientes verificaciones son obligatorias antes del primer despliegue a Certificación:

- Service Project creado, conectado a la Shared VPC del Host del entorno correspondiente.
- Service Accounts dedicadas creadas, con permisos de mínimo privilegio asignados por recurso.
- Cloud Run desplegado con `--ingress=internal` y Direct VPC egress.
- AlloyDB o Cloud SQL con Private IP, sin endpoint público.
- Secret Manager con secretos del proyecto cargados y referenciados en runtime.
- Contrato OpenAPI 3 publicado en repositorio, validado en API Management.
- Headers de request validados, headers de response inyectados, request-id en todos los logs.
- WAF Cloud Armor activo en el Load Balancer correspondiente al entorno.
- Pipeline de CI/CD con SonarQube, escaneo de dependencias y de imagen funcionando.
- Logs estructurados JSON con los cinco campos obligatorios, exportados a Cloud Logging y a SIEM.
- Datos sensibles ofuscados según la tabla de la sección 8.1.
- Stores separados para logs y datos de negocio.
- Cifrado en reposo verificado.
- Si hay agentes IA: identidad por agente, supervisor, lista blanca de tools, mitigación de prompt injection.
- Si hay frontend: IAP o CIAM, CSP estricto, comunicación al backend vía API Management.
- Si hay usuarios humanos: módulo de auditoría aplicativa, reportería de usuarios y perfiles, cumplimiento de la política de contraseñas si aplica auth local.
- Plan de rotación de secretos calendarizado.
- Plan de retención de logs documentado.
- Visto bueno del equipo de Arquitectura de Soluciones.
- Visto bueno del equipo de Seguridad de la Información.

---

## 16. Glosario rápido

- **API Management** — capa de API gateway que valida contratos OpenAPI, aplica políticas de tráfico y enruta.
- **BIAN** — Banking Industry Architecture Network. Taxonomía de dominios y servicios para el sector bancario.
- **CIAM** — Customer Identity and Access Management. Sistemas de identidad para usuarios externos (comercios, clientes finales).
- **Cloud Armor** — WAF gestionado de GCP.
- **Cloud NAT** — servicio NAT gestionado para tráfico saliente desde la VPC.
- **CMEK** — Customer-Managed Encryption Keys. Claves de cifrado controladas por el cliente, no por el proveedor.
- **Direct VPC egress** — método moderno de conectar Cloud Run a la VPC, reemplaza al Serverless VPC Connector.
- **Entra ID** — IDP de Microsoft, antes Azure Active Directory.
- **Host Project** — proyecto GCP que aloja la Shared VPC y los servicios transversales.
- **IAP** — Identity-Aware Proxy. Controla el acceso a aplicaciones y recursos según la identidad del usuario.
- **OpenAPI 3** — especificación de contratos de API REST, sucesora de Swagger 2.
- **PCI-DSS** — Payment Card Industry Data Security Standard. Norma obligatoria para procesadores de pagos.
- **Service Project** — proyecto GCP que aloja la carga de trabajo de una iniciativa, conectado a la Shared VPC del Host.
- **Shared VPC** — modelo de red de GCP donde una VPC en un Host se comparte con múltiples Service Projects.
- **SIEM** — Security Information and Event Management. Plataforma de correlación de eventos de seguridad.
- **Spinal-case** — convención de nombres con palabras separadas por guiones, en minúsculas. Ejemplo: `reviewed-documents`.

---

## 17. Cierre

Este documento es la base sobre la cual se diseñará la arquitectura GCP de REVIDOC y de cualquier proyecto IA o de apificación que se levante en Izipay a partir de mayo de 2026. La forma de aplicarlo no es leerlo una vez al inicio del proyecto: es revisarlo en cada hito de diseño, en cada PR a producción, en cada incidente, y mantenerlo vivo conforme la realidad del producto y de las amenazas evolucione.

La reutilización de este conocimiento en otros contextos —empresas distintas, regulaciones distintas, hyperscalers distintos— es un objetivo explícito de su redacción.

---

*Fin del documento.*
