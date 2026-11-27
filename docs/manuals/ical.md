# Manual iCal

Como ligar o calendário Airbnb de um imóvel ao Clean&Check e o que esperar da sincronização.

## 1. Ligar um calendário

1. No Airbnb, no calendário do anúncio, exporte o calendário e copie o link `.ics`.
2. No Clean&Check, abra **Fontes iCal**, escolha o imóvel e carregue em **+ Nova fonte**.
3. Cole o link e guarde. O link tem de começar por `https://`; o mesmo link não pode ser registado duas vezes no mesmo imóvel.
4. Carregue em **Sincronizar agora** para a primeira leitura.

O link funciona como uma chave: quem o tiver vê as datas do anúncio. Por isso o Clean&Check mostra só o endereço sem a parte privada.

## 2. Quando sincroniza

- **Automaticamente a cada 30 minutos**, para todas as fontes ativas. Requer o agendador e a fila da API a correr.
- **À mão**, com **Sincronizar agora**.
- O Airbnb atualiza o próprio ficheiro de exportação com algum atraso. Uma alteração feita no Airbnb pode demorar a aparecer, mesmo com uma sincronização manual.

## 3. O que é importado

| Do Airbnb | No Clean&Check |
|---|---|
| Reserva (`Reserved`) com datas de entrada e saída | Reserva + tarefa de limpeza após o check-out |
| Bloqueio (`Airbnb (Not available)`) | Ignorado |
| Evento com outro título | Não importado; aparece como aviso no resultado |
| Evento com hora (não só data) | Não importado; aparece como aviso |

**O que o iCal do Airbnb não traz** e, por isso, o Clean&Check não mostra: nome do hóspede, número de hóspedes, contacto, preço e horas de entrada e saída. As horas usadas nas tarefas são as horas de referência definidas no imóvel.

## 4. Alterações e cancelamentos

- **Datas alteradas no Airbnb**: a reserva é atualizada, a tarefa é reagendada e o prestador recebe um aviso. Se a equipa tiver ajustado as datas à mão (**Ajustar**), o ajuste mantém-se; **Repor datas do Airbnb** volta às datas recebidas.
- **Reserva cancelada**: o Airbnb não envia um cancelamento, a reserva simplesmente desaparece do ficheiro. O Clean&Check só a cancela quando:
  - a leitura do calendário foi completa e com sucesso;
  - o check-out ainda é no futuro (estadias passadas saem do ficheiro sozinhas e não são tocadas).
- A tarefa de uma reserva cancelada é cancelada e o prestador é avisado. Se a tarefa já estiver em curso ou concluída, mantém-se.
- Um ficheiro vazio ou inválido **nunca** cancela reservas.

## 5. Estados de uma fonte

| Estado | Significado | O que fazer |
|---|---|---|
| Sincronizada | Última leitura correu bem, com o número de reservas novas, atualizadas e canceladas | Nada |
| Em curso | A sincronização está a correr; a página atualiza sozinha | Esperar |
| Falhou | A última leitura falhou; o motivo aparece por baixo. Nenhuma reserva foi apagada | Seguir os passos em **Como recuperar** |
| Interrompida | Começou há mais de 5 minutos e não terminou (ex.: servidor reiniciado) | A próxima sincronização fecha-a; pode usar **Sincronizar agora** |
| Desatualizada | Sem sucesso há mais de 2 horas | Confirmar o link no Airbnb e que o agendador e a fila estão a correr |

Erros mais comuns:

- *Não foi possível ligar ao servidor* / *respondeu com o estado 5xx*: o Airbnb esteve indisponível. Tentar mais tarde.
- *respondeu com o estado 404*: o link deixou de existir. Exportar de novo no Airbnb e usar **Trocar link**.
- *O ficheiro não é um VCALENDAR válido*: o link não aponta para um calendário.

## 6. Limites

- Só links `https://` públicos; endereços de redes privadas ou locais são recusados.
- Ficheiro até 2 MB, 15 s para responder, até 3 redirecionamentos.
- Medido em desenvolvimento: 400 calendários sincronizam em ~80 s por ciclo, sem reservas perdidas nem duplicadas (ver `Docs/benchmarks/ical-sync.md`).
