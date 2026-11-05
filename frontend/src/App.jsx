import Loja from './Loja.jsx'
import Painel from './Painel.jsx'

// o painel nao tem link na loja: o operador abre direto por /painel
const noPainel = window.location.pathname.replace(/\/+$/, '') === '/painel'

export default function App() {
  return (
    <>
      <nav>
        <span className="brand">
          <img src="/logo.png" alt="A Sorte Tá de Pé" width="480" height="417" />
        </span>
      </nav>
      <main>{noPainel ? <Painel /> : <Loja />}</main>
      {!noPainel && (
        <footer className="rodape">
          <p>
            Promoção da SABRINA MOURA DE MESQUITA MARTINEZ, CNPJ 56.014.314/0001-06 com a distribuição gratuita de prêmios por meio de sorteios
            lastreados em Títulos de Capitalização da Modalidade Incentivo de Contribuição Única, aprovados pelo Processo SUSEP n.º 15414.629765/2023-36,
            garantidos pela CAPEMISA Capitalização S/A, CNPJ 14.056.028/0001-55. É proibida a venda de título de capitalização a menores de dezesseis anos.
            O valor não exigido dentro do prazo prescricional, estabelecido pela legislação em vigor, acarretará a perda desse direito. Leia o regulamento
            completo em <a href="https://tadepeviagens.com.br/" target="_blank" rel="noopener noreferrer">https://tadepeviagens.com.br/</a>.
            SAC CAPEMISA Capitalização S/A 0800 940 1130 ou 0800 291 2246, disponível 24h por dia, sete dias por semana. Ouvidoria 0800 707 4936 ou
            0800 291 2248, de segunda a sexta-feira, das 8h às 17h. Antes de contratar consulte previamente as Condições Gerais. As condições
            contratuais/regulamento deste produto protocolizadas pela sociedade junto à SUSEP poderão ser consultadas no endereço eletrônico
            {' '}<a href="https://www.susep.gov.br" target="_blank" rel="noopener noreferrer">www.susep.gov.br</a>, de acordo com o número de processo
            constante da proposta. Imagens meramente ilustrativas, sem relação com a premiação. Prêmio de sorteio pago exclusivamente em dinheiro.
            Prêmio líquido de Imposto de Renda.
          </p>
        </footer>
      )}
    </>
  )
}
