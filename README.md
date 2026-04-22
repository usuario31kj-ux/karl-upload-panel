# Karl Upload Panel

Panel privado de upload de archivos al VPS karl-gateway.

## Deploy

Auto-deploy configurado:
- **Repo**: github.com/usuario31kj-ux/karl-upload-panel
- **Sitio**: springgreen-sheep-190531.hostingersite.com
- **Webhook**: Hostinger auto-pull en cada push a main

## Uso

1. Abrir https://springgreen-sheep-190531.hostingersite.com
2. Ingresar password
3. Arrastrar archivo al dropzone (max 500 MB)
4. El archivo va directo al VPS via gateway.srv1593908.hstgr.cloud/upload/file
5. Ver lista de archivos subidos en tiempo real

## Seguridad

- Password check SHA256 client-side
- Agent-key header server-side (x-agent-key: izi-karl-2026)
- CORS restringido
- No indexable (robots meta + nombre carpeta random)
- Backend valida path dentro de whitelist antes de escribir

## Arquitectura

```
Browser drag-drop
  ↓
POST multipart form + x-agent-key
  ↓
https://gateway.srv1593908.hstgr.cloud/upload/file
  ↓
/openclaw-data/uploads/chat-exports/ en VPS Hostinger
```
