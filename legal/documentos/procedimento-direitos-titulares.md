# Procedimento: pedidos de direitos dos titulares (interno)

Como responder a quem pede acesso, retificação, apagamento, limitação, portabilidade ou oposição. **Modelo a rever.** Última revisão: {{atualizado}}.

## Prazo
Responder **sem demora injustificada e no máximo em 1 mês** a contar da receção. Pode ser prorrogado por mais 2 meses em casos complexos, avisando a pessoa no primeiro mês.

## Passos
1. **Receber e registar** o pedido (data, quem pede, o que pede). Ponto de contacto: o email de privacidade em `config/legal.php`.
2. **Confirmar a identidade** de quem pede (por exemplo, o pedido vir do email da conta ou a pessoa estar autenticada). Não pedir mais dados do que o necessário.
3. **Executar**:

| Direito | Como se cumpre no Lumina |
|---|---|
| Acesso e portabilidade | A pessoa descarrega tudo em Área pessoal > "Descarregar os meus dados" (JSON). Se pedir por email, enviar esse ficheiro por canal seguro. |
| Retificação | A pessoa edita o perfil e a empresa na Área pessoal. O resto, o administrador corrige. |
| Apagamento | O administrador usa "Eliminar a minha conta". Um funcionário pede ao administrador para ser eliminado (aba Equipa). |
| Limitação / oposição | Desativar a conta (aba Equipa) ou desligar a função (Google, Lumina). Avaliar caso a caso. |
| Retirar consentimento | Desligar o Google Calendar ou a localização; fica registado em `consent_log`. |

4. **Responder** por escrito, dizendo o que foi feito (ou porque não foi possível, com a base legal).
5. **Informar** que pode reclamar à CNPD (<https://www.cnpd.pt>).

## Quando recusar ou limitar
Só com fundamento: pedidos manifestamente infundados ou excessivos, ou dados que a lei obrigue a conservar. Registar sempre a decisão e a razão.

## Pedidos sobre dados de clientes dos nossos clientes
Se alguém pedir acesso a dados que um negócio registou no Lumina (por exemplo, um cliente final), o Lumina é **subcontratante**: encaminhar o pedido para o negócio (responsável pelo tratamento) e ajudá-lo a cumprir.

## Funcionários e o acompanhamento da equipa

- O descarregamento automático de um **funcionário** inclui as mensagens, as tarefas, as metas, os avisos, as entradas no sistema e os comentários que o administrador **partilhou** com ele.
- As **observações privadas** do administrador sobre um funcionário **não** saem desse descarregamento automático. Se o funcionário pedir acesso aos seus dados pessoais, o administrador deve tratar o pedido (por exemplo, entregando essas observações) e, em caso de dúvida, pedir aconselhamento jurídico. O descarregamento do **administrador** inclui todas as observações que registou.
- Cada funcionário vê, na aba «Mensagens e tarefas», o que o administrador pode ver sobre ele.

