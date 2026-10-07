# Política de retenção de dados (interno)

Quanto tempo se guarda cada tipo de dados e como é apagado. **Modelo a rever.** Última revisão: {{atualizado}}.

| Dados | Quanto tempo | Como se apaga |
|---|---|---|
| Conta, empresa e dados do negócio (movimentos, clientes, produtos, notas, agenda, tempo...) | Enquanto a conta existir | Pelo administrador, em "Eliminar a minha conta" (apaga tudo, em cascata) |
| Sessões iniciadas | Valem 14 dias; o registo guarda-se 30 dias depois de terminar | Automático (limpeza ocasional) |
| Tentativas falhadas de login | Cerca de 1 dia | Automático |
| Links enviados por email (recuperar palavra-passe, confirmar email, convites) | Valem de 1 hora a 3 dias; apagam-se 7 dias depois de expirarem | Automático |
| Registo de consultas da Lumina (assistente de IA) | 180 dias | Automático |
| Entradas no sistema dos funcionários (acompanhamento da equipa) | 90 dias | Automático (limpeza ocasional) |
| Avisos (o sino) | Lidos: 60 dias depois de lidos. Todos: 180 dias | Automático (limpeza ocasional) |
| Mensagens, tarefas, metas e observações do líder sobre funcionários | Enquanto a conta existir | Apagados com o funcionário ou com a conta |
| Anexos (fotos e PDF de recibos) | Enquanto o registo a que pertencem existir | Apagados com o registo ou com a conta (os ficheiros também) |
| Registo de ações sensíveis (auditoria) | Enquanto a conta existir | Apagado com a conta |
| Conversas com a Lumina | Enquanto a conta existir | Apagadas com a conta |
| Tokens do Google Calendar | Até desligar o Google ou eliminar a conta | O acesso é revogado na Google ao desligar |
| Emails de teste (driver "log") | Só em desenvolvimento | Apagar a pasta `storage/mail/` |

## Por rever

- Definir um prazo para **contas inativas** (por exemplo, avisar e eliminar após 24 meses sem entrar). Hoje não existe limpeza automática de contas inativas.
- Cópias de segurança da base de dados feitas por quem aloja o sistema: definir quanto tempo se guardam e como se garante que dados eliminados deixam de existir nelas ao fim desse prazo.
