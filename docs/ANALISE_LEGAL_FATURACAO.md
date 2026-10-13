# Faturação certificada em Portugal: análise para o Lumina

Data: 13 de outubro de 2026. **Isto é uma análise informativa, não aconselhamento jurídico.** Nenhum código foi escrito para faturação. As fontes abaixo são **páginas de fornecedores de software** (Vendus), não o texto oficial; antes de decidir, confirmar tudo no Portal das Finanças e no Diário da República, ou com um contabilista certificado.

## Conclusão curta

O Lumina **não deve emitir faturas** enquanto o programa não for certificado pela Autoridade Tributária (AT). Emitir faturas com um programa que não esteja certificado, quando o utilizador está obrigado a usar um certificado, expõe o **utilizador** a coimas (a fonte indica 1 500 € a 18 750 €). O caminho seguro é o Lumina continuar a ser uma **ferramenta de gestão** (caixa, estoque, relatórios) e, se se quiser faturação, **integrar com um programa já certificado** em vez de a construir.

## O que a fonte diz (por confirmar no texto oficial)

| Tema | O que se afirma | Fonte |
|---|---|---|
| Quem é obrigado a usar programa certificado | Sujeitos passivos de IRS ou IRC com volume de negócios do ano anterior acima de 50 000 €; em início de atividade, volume anualizado acima de 50 000 €; quem já usa programas de faturação; quem tem contabilidade organizada | [Vendus](https://www.vendus.pt/blog/obrigado-utilizar-software-certificado/) |
| Coimas por não usar programa certificado | 1 500 € a 18 750 € (RGIT) | [Vendus](https://www.vendus.pt/blog/obrigado-utilizar-software-certificado/) |
| Requisitos técnicos de um programa certificado | Exportar SAF-T; identificar a gravação de faturas e retificativos; autenticar cada utilizador; não ter funções que alterem informação fiscal | [Vendus](https://www.vendus.pt/blog/obrigado-utilizar-software-certificado/) |
| Faturas | Elementos obrigatórios (NIF, designação, taxa de IVA ou motivo de isenção), séries com numeração contínua, QR Code e ATCUD gerados pelo programa | [Vendus, DL 28/2019](https://www.vendus.pt/blog/decreto-lei-28-2019-regras-de-faturacao/) |
| Comunicação à AT | Faturas do mês anterior (ou inexistência de faturação), por transmissão em tempo real, SAF-T (PT) ou Portal das Finanças; dia exato do prazo não confirmado | [Vendus, DL 28/2019](https://www.vendus.pt/blog/decreto-lei-28-2019-regras-de-faturacao/) |
| Conservação | Cerca de 10 anos | [Vendus, DL 28/2019](https://www.vendus.pt/blog/decreto-lei-28-2019-regras-de-faturacao/) |
| Diploma técnico da certificação | [Portaria 363/2010](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Portaria_363_2010.pdf) (não li o texto completo) | Portal das Finanças |

Pontos que **não** consegui confirmar: as datas e artigos exatos, a entrada em vigor da assinatura digital qualificada (a fonte diz «prevista para 2027»), o processo e o custo da certificação para um produtor de software, e se as regras mudaram em 2025-2026.

## Opções para o Lumina

| Opção | O que implica | Risco / custo | Recomendação |
|---|---|---|---|
| A. Não faturar (estado atual) | O Lumina regista vendas e caixa; a fatura emite-se noutro programa | Nenhum risco fiscal para o Lumina; o utilizador faz dupla introdução | **Recomendada para a PAP** |
| B. Integrar com programa certificado | Ligar a uma API de terceiros (por exemplo, o utilizador cola a sua chave) para criar a fatura lá | Dados de clientes saem para o fornecedor (RGPD, contrato, transferência); dependência da API; precisa de autorização tua | Possível depois da PAP, com análise de privacidade |
| C. Exportar dados para o programa de faturação | CSV com vendas para importar no programa certificado | Baixo; já existe exportação CSV de movimentos | Bom passo intermédio |
| D. Certificar o Lumina | Pedir certificação à AT: cumprir SAF-T, séries, ATCUD, QR code, assinatura, auditoria, manutenção | Alto: custo, tempo, responsabilidade permanente; não realista para uma PAP | **Não recomendada** |

## Regras para o código se um dia se avançar

- Nunca gerar nem alterar documentos com valor fiscal no Lumina sem certificação.
- Se houver integração (opção B), as chaves ficam só em variáveis de ambiente, nunca no repositório, e antes de ativar documenta-se que dados são enviados, para onde, com que base legal e por quanto tempo (ver `docs/RGPD_INTEGRACOES.md`).
- O texto no site deve dizer claramente que o Lumina **não é um programa de faturação certificado** (a rever com um jurista).

## Pergunta para a defesa da PAP

«Porque é que o Lumina não emite faturas?» Resposta honesta: porque a lei exige programas certificados pela AT para quem está abrangido, a certificação é um processo próprio e o Lumina foca-se na gestão do negócio; a faturação faz-se num programa certificado, com exportação de dados para evitar trabalho repetido.

Related: [RGPD e integrações](RGPD_INTEGRACOES.md), [SWOT do Lumina](SWOT_LUMINA.md)
